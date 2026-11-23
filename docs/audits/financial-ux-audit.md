# Somiti Manager — Financial Accuracy & UX Audit

* **Author:** Rabbi (audit prepared with Claude Code)
* **Date:** 2026-11-22
* **Scope:** monthly collection (chada), payments, advance, reversals, receipts, member portal, admin collection screen, dashboards, reports, database integrity.
* **Method:** source reading, database schema review, 10 hand-calculated scenario tests run against the real Actions on the PostgreSQL test database, the existing concurrency and 1,000-seed invariant suites, and a click-through of the local demo (member portal as `01711000003`, collection screen as `cashier@somiti.test`). No source code was changed; no production system was touched.

---

## A. Executive summary

**The money engine is sound.** Every scenario I calculated by hand matched the application to the poisha: oldest-first allocation, part-payments, under-payments, multi-month advance, reversal, and a ৳500 → ৳600 rate change with advance. Money is integer poisha, every write runs in a locked transaction, the database computes `outstanding_poisha` itself and refuses `paid > amount`, payments need two different people, and the nightly integrity check ties the ledger to the member sub-ledgers. The 27 concurrency and property tests pass. I found **no confirmed calculation bug**.

**The weak points are rules and presentation, not arithmetic:**

1. **A late joiner cannot be charged back-dues at all** (your ৳5,000 example). The app refuses to start a member before the latest generated month, and there is no other way to bill earlier months. If the society requires catch-up from the starting month, today it has to be handled outside the app.
2. **Receipts are not frozen.** A receipt's "paid through" line is calculated when it is printed, not when the payment was made, so the same receipt shows different text next month. Receipts also do not show "due before / due after this payment".
3. **The member home screen shows eight equal-sized cards**, four of which are usually ৳0.00, and the "monthly deposit" card (৳1,800) does not match what the member actually pays each month (৳1,860, including service charge). The three numbers a member cares about — owe, paid, advance — are not given priority.
4. **The member's "Dues" page opens empty when nothing is owed**, so a member cannot see which months are paid without clearing a filter. The receipts list does not say which months each payment covered.
5. **Duplicate cash entries are warned about, not prevented.** Two identical ৳500 cash entries on the same day both get approved and the second silently becomes advance.

None of these lose money, but items 1, 2 and 5 can make a member's view disagree with what the committee believes, which is exactly the trust problem the app exists to solve.

---

## B. Module-by-module audit

| Module / screen | What works | Problems found |
|---|---|---|
| **Due generation** (`GenerateMonthlyDues`, `MonthlyDueBuilder`) | Idempotent (natural key + `insertOrIgnore`), cache lock per month, no gaps or far-future months, advance auto-applied after generation | Only bills members with an active share lot in that month → no back-dues for late joiners (F-01) |
| **Record payment** (`RecordPayment`) | Idempotency key with advisory lock, TrxID format + unique `(method, trx_id)`, future dates refused, members may only submit their own bKash/Nagad | Same-day same-amount cash duplicates only warned (F-05) |
| **Approve payment** (`ApprovePayment`) | Locks payment → member → dues, status re-checked under lock, approver ≠ recorder (also a DB CHECK), journal posted in same transaction, allocations and advance written atomically | If no rate plan exists for either the receipt month or the current month, `$plan->advance_policy` reads a property on null (F-12) |
| **Allocation** (`AllocationEngine`) | Oldest month first, then configurable type order (late fee → service charge → registration → deposit) | None |
| **Reversal** (`ReversePayment`) | Dues reopen, books tie out (scenario S9) | — (see existing open gap #2 for exits/year-end) |
| **Advance** (`AdvanceLedger`, `ApplyAdvance`, refunds) | Ledger with `balance_after_poisha >= 0` CHECK; reversible refunds | After an advance-refund reversal the member can hold open dues **and** advance at once until the next monthly run (F-06) |
| **Waivers** (`WaiveLateFee`) | Late-fee waiver with reason | No waiver for deposit/service-charge dues (F-09, needs a decision) |
| **Receipt PDF** (`ReceiptDocument`, `reports/pdf/receipt.blade.php`) | Voucher no., member, method, TrxID, month-by-month allocation, advance held, recorded/approved by | "Paid through" computed live (F-02); no before/after outstanding (F-03) |
| **Collect Payment page** (`app/Filament/Pages/Collections/CollectPayment.php`) | Member search by no./name/mobile, open dues with "clear up to here" amounts, allocation preview, oldest-first explanation, duplicate warning, T2 confirm | Dues panel sits below the form; save button is top-right away from the form; each month is three rows (F-08) |
| **Member home** (`MemberStatsWidget`) | Bangla digits, greeting, paid-through, outstanding links to dues | 8 equal cards, ".00" noise, monthly-deposit figure excludes service charge, accounting words ("মোট ধার্য") (F-04) |
| **Member dues** (`app/Filament/Member/Pages/Dues.php`) | Amount / paid / outstanding columns with totals | Default filter "open" → empty page when up to date; no "partly paid" label; no month strip (F-07) |
| **Member receipts** (`Payments.php`) | Status badges, printable receipt per row | List does not show which months each payment covered (F-07) |
| **Admin dashboard** (`SomitiOverviewWidget`, `AlertsWidget`) | Active members, overdue count → defaulters, collected this month, savings, cash, bank, integrity status | No expected-vs-collected for the month, no total outstanding, no total advance held (F-10) |
| **Reports** | Member statement, collection summary, defaulters, cash book, share/dividend/investment registers, ledger, trial balance, balance sheet, integrity, audit log, advance balances | "Collected this month" counted by receipt date on dashboard vs voucher date in reports (already documented as open gap #13) |

---

## C. Financial accuracy report

### C.1 Scenario results (run on the real Actions; file kept out of the commit, see §I)

Plan for all scenarios: 1 share, ৳500/month, no service charge, no registration fee, default advance policy.

| # | Scenario | Hand-calculated expectation | App result | Verdict |
|---|---|---|---|---|
| S1 | Joins at start, pays ৳500 for Jul | Jul settled, owes ৳0, paid through Jul | Same | ✅ |
| S2 | Joins in Nov, society requires Jul–Oct | Jul–Nov dues = ৳2,500 | **Refused**: "dues already generated up to 2026-11, cannot change shares from 2026-07". Joining from Dec gives ৳0 back-dues | ❌ rule gap (F-01) |
| S3/S4 | Owes Jul–Nov (৳2,500), pays ৳1,500, later ৳1,000 | Jul–Sep settled, Oct–Nov open, ৳1,000 due, paid through Sep; then ৳0, paid through Nov | Same | ✅ |
| S5 | Pays ৳3,000 in Jul | ৳2,500 advance, used by Aug–Dec, paid through Dec | Same | ✅ |
| S6 | Pays ৳300 of ৳500 | Jul open with ৳200 left, not "paid" | Same; paid-through stays empty | ✅ |
| S7 | Waiver | — | Only late fees can be waived | ⚠️ decision (F-09) |
| S8/S9 | Reverse a ৳1,000 payment covering Jul+Aug | Both reopen, ৳1,000 owed, books tie out | Same | ✅ |
| S10 | Same ৳500 cash entered twice | Second should be stopped or flagged for checker | Both approved; ৳500 becomes advance | ⚠️ (F-05) |
| S10b | Same bKash TrxID twice | Refused | Refused | ✅ |
| S11 | Two checkers approve together | Posted once | Posted once (`tests/Concurrency/PaymentApprovalTest.php`) | ✅ |
| S12 | Member exit | Settlement per BR-22 | Covered by existing exit tests; refundable share capital still open (known gap #7) | ⚠️ decision |
| S13 | ৳500 → ৳600 from Sep with ৳1,000 advance | Aug ৳500 from advance, Sep ৳500 from advance + ৳100 owed | Same | ✅ |
| S14 | Advance refund reversed while ৳500 is owed | Advance should clear the ৳500 | ৳500 owed **and** ৳1,000 advance shown together | ⚠️ (F-06) |

### C.2 Accounting principles checklist

| Principle | Status | Evidence |
|---|---|---|
| No silent overwrite of financial records | ✅ | Model guards + PostgreSQL triggers (spec §3.6); reversals only |
| No float money | ✅ | `Money` value object, BIGINT `_poisha` columns, arch tests in `tests/Arch` |
| Database transactions + row locks | ✅ | `DB::transaction(..., attempts: 3)` + `lockForUpdate()` in every financial Action |
| Unique reference per payment | ✅ | Voucher number sequence + `idempotency_key` + unique `(method, trx_id)` |
| Duplicate submission prevention | ⚠️ partial | Retries prevented; human duplicate cash entries only warned (F-05) |
| Maker/checker | ✅ | `CHECK (approved_by <> recorded_by)` |
| Double entry | ✅ | Debit-xor-credit CHECK per line; balanced-journal trigger; nightly integrity run |
| Sub-ledger ↔ GL tie-out | ✅ | `assertBooksTieOut()` passes after every scenario |
| Reversals traceable | ✅ | `reversal_journal_entry_id`, status CHECKs |
| Every adjustment has reason + user | ✅ for reversals/waivers/refunds | Reason required in the Actions |

---

## D. Monthly collection audit

**Current flow (cash):** cashier opens *Collect Payment* → picks member → sees open dues and "clear up to here" amounts → types amount → sees preview of what will be settled → confirms (T2) → payment is **pending** → a second person approves → journal posted, receipt available, SMS sent.

What is good: the oldest-first rule is explained on the screen, the cashier can click a month's running total instead of doing arithmetic, and the preview shows the effect before saving.

What slows it down or confuses:

1. **Order on the page.** The member's dues are below the form; on a laptop the cashier must scroll past method/amount/date/proof/notes to see what is owed, then scroll back up to the save button at the top right.
2. **Three rows per month** (late fee, service charge, deposit). A collector thinks "August = ৳640", not three lines.
3. **No receipt at the door.** Because a second person must approve, the member leaves with nothing. That is the right control, but the member needs a "received, waiting for approval" slip or SMS at recording time.
4. **Your step 5, "admin selects months", is deliberately not offered.** I recommend keeping it that way (oldest first is standard and prevents skipping months that carry late fees). The "clear up to here" buttons already give the collector a month-based way to choose an amount.

**Recommended collection screen (3 steps):**

```
┌ Find member: [ M-0004 / name / mobile ......... ] ┐
│ Jamal Hossain · M-0004                             │
│ OWES ৳1,920   ·   paid through July 2026  ·  advance ৳0
│ Aug ● ৳640   Sep ● ৳640   Oct ● ৳640   Nov ○ not yet billed
│   (tap a month = pay up to and including it)       │
├────────────────────────────────────────────────────┤
│ Amount [ ৳1,280 ]  Method [Cash ▾]  Date [today]   │
│ → settles Aug + Sep. Still owed after: ৳640 (Oct)  │
│ [ More: proof, notes ▸ ]                           │
│                         [ Record ৳1,280 ]          │
└────────────────────────────────────────────────────┘
```

---

## E. UX/UI improvement plan

### Member home (phone first)

Replace the eight cards with one summary block and a month strip:

```
আসসালামু আলাইকুম, সালমা বেগম

┌──────────────────────────────┐
│ আপনার বকেয়া        ৳ ০        │  ← biggest, red if > 0, green tick if 0
│ অক্টোবর ২০২৬ পর্যন্ত পরিশোধিত    │
└──────────────────────────────┘
 মোট জমা ৳২৮,২৬০   অগ্রিম ৳০   সঞ্চয় ৳২৭,০০০

 জুল ✓  আগ ✓  সেপ ✓  অক্টো ✓  নভে ◔ (৳৬২০ বাকি)

 প্রতি মাসে দিতে হয়: ৳১,৮৬০ (জমা ৳১,৮০০ + সার্ভিস চার্জ ৳৬০)
 [ বিকাশ/নগদে পরিশোধ করুন ]

 সাম্প্রতিক পরিশোধ
 ৪ অক্টো · নগদ · ৳১,৮৬০ · অক্টোবর মাস ✓
```

Specific changes:

* Drop ".00" when poisha are zero (show `৳২৭,০০০`).
* Replace "মোট ধার্য" (total levied) with "এ পর্যন্ত মোট দেওয়ার কথা" or remove; explain the gap between "savings" and "total paid" in one line (service charge and registration fee are not savings).
* Show the true monthly amount (deposit + service charge).
* Month strip states: paid ✓, partly paid ◔ with remainder, unpaid ●, waived (strikethrough), covered by advance, pending approval (clock), reversed (shown in history only).
* Dues page: default to "all months" grouped by month, newest first, one row per month with an expandable breakdown.
* Receipts list: add a "for months" column (e.g. "Aug–Sep 2026").
* Empty states that explain the next step ("কোনো বকেয়া নেই। পরের মাসের ৳১,৮৬০ ১০ ডিসেম্বরের মধ্যে দিন").

### Receipt

Add: amount due before this payment, amount received, amount still due after, and freeze those numbers (plus paid-through) at approval time.

### Admin dashboard

Add one "this month" strip: **billed ৳X · collected ৳Y (Z%) · still owed ৳W · advance held ৳V · waiting for approval N**. Label each with its counting rule (by due month vs by receipt date) so the figures never look contradictory.

### Not verified

The phone layout could not be checked: the browser window would not shrink below desktop width. A real-phone pass (360 px) is still needed, especially for the dues table, which has 7 columns.

---

## F. Database & architecture findings

* **Strong:** generated `outstanding_poisha`, `dues_paid_within_amount` CHECK, debit-xor-credit CHECK, maker ≠ checker CHECK, status/journal consistency CHECKs, unique `(method, trx_id)`, first-of-month CHECKs, advance balance ≥ 0 CHECK, fixed lock order.
* **Duplicated calculation:** "monthly amount × shares → months covered" is computed in both `PaidThroughCalculator::estimatedMonths` and `PaymentPreviewer::coversThrough`, with slightly different starting months. They can disagree on the collect screen vs the receipt. Extract one service.
* **Live-computed historical data:** receipts read today's paid-through (F-02). Anything printed as a record should read a snapshot stored at approval.
* **Back-dues and dividends are coupled through share lots:** dues come from share lots, and dividends are weighted by share-months (`app/Domain/YearEnd/Actions/PrepareYearEnd.php`). Backdating shares to create back-dues would also pay dividends for months the member was not in. Back-dues need their own mechanism.
* **Scale:** generation is chunked; allocation reads only one member's open dues; no obvious N+1 in the money path. The earlier production out-of-memory issue (see `docs/designs/production-oom-brief.md`) is the bigger scaling risk.

---

## G. Prioritised issue list

Confirmed = reproduced by a test or seen in the running app. Risk = reasoned from code, not reproduced. Decision = needs the committee.

| ID | Severity | Type | Module | Problem | Evidence | Fix | Migration? |
|---|---|---|---|---|---|---|---|
| F-01 | **High** | Confirmed gap | Dues / members | Late joiner cannot be billed months before joining; no catch-up mechanism | `ShareChanger.php:196`, scenario S2 | Per-member "dues start month" (default: society start), creating catch-up deposit dues at join time without backdating shares; preview total before confirm | Yes: `members.dues_start_month` (or a `catch_up` due source) |
| F-02 | **High** | Confirmed | Receipt | Paid-through and estimate on a receipt are recalculated at print time | `app/Reports/ReceiptDocument.php:37-38` | Store a receipt snapshot (before/after outstanding, paid-through, advance) on approval; print from it | Yes: snapshot columns or `receipts` table |
| F-03 | Medium | Confirmed | Receipt | No "due before / due after" on the receipt | `resources/views/reports/pdf/receipt.blade.php` | Add both, from the snapshot | With F-02 |
| F-04 | Medium | Confirmed (UI) | Member home | 8 equal cards; monthly-deposit card ৳1,800 vs ৳1,860 actually paid; ".00"; accounting words | `MemberStatsWidget.php`, demo screenshot | Summary block + month strip (§E) | No |
| F-05 | Medium | Confirmed | Collections | Same-day same-amount cash duplicates both approved; extra goes to advance | Scenario S10; `SimilarPayments` only warns | Require a typed reason to save a flagged duplicate, and show the flag on the approval queue | No |
| F-06 | Medium | Confirmed | Advance | Advance and open dues coexist after an advance-refund reversal until the next monthly run | Scenario S14; `ReverseAdvanceRefund.php:30` | Call `ApplyAdvance` for that member inside the same transaction | No |
| F-07 | Medium | Confirmed (UI) | Member dues/receipts | Dues page empty by default; no partly-paid label; receipts list lacks months covered | `Member/Pages/Dues.php` filter default; demo | Show all months, add "partly paid" label and a "for months" column | No |
| F-08 | Medium | Confirmed (UI) | Collect page | Dues below form, save button far away, 3 rows per month | `CollectPayment.php`, demo | Layout per §D | No |
| F-09 | Medium | Decision | Waivers | Only late fees can be waived | `WaiveLateFee.php` is the only waiver | Decide whether committee can waive deposit/service dues (with resolution + second person) | Maybe |
| F-10 | Medium | Confirmed (UI) | Admin dashboard | No expected vs collected, total outstanding, advance held, or pending count in one place | `SomitiOverviewWidget.php` | "This month" strip (§E) | No |
| F-11 | Low | Risk | Architecture | Two "months covered by advance" calculations | `PaidThroughCalculator`, `PaymentPreviewer` | One shared service | No |
| F-12 | Low | Risk | Approve payment | Null rate plan → property read on null instead of a clear `DomainRuleViolation` | `ApprovePayment.php` plan lookup | Throw `members.errors.no_rate_plan` | No |
| F-13 | Low | Confirmed (UI) | Member + collect | No "received, waiting for approval" confirmation to the member at recording time | `RecordPayment` notifies staff only | SMS/portal note at recording | No |
| F-14 | Open | Not verified | Mobile | Phone layout untested | Browser could not narrow | Test at 360 px on a device | — |
| F-15 | Medium | Confirmed (code) | Reversal | `ReversePayment` reopens dues but never re-applies the member's advance, so dues and advance can coexist after a reversal too (same effect as F-06) | `ReversePayment.php` has no `ApplyAdvance` call | Re-apply advance for that member at the end of both reversal Actions; add a "stranded advance" nightly check | No |
| F-16 | Medium | Confirmed (code) | Allocation | Preview and approval settle **any** open due, including not-yet-due ones; `ApplyAdvance` only settles dues with `due_date` ≤ end of current month. The three paths can disagree | `ApplyAdvance.php:80` vs `AllocationEngine`/`PaymentPreviewer` | One written eligibility rule used by all three | No |
| F-17 | Medium | Confirmed (code) | Member home | "বর্তমান বকেয়া" (current outstanding) sums **all** open dues, including months not yet due | `MemberSummary.php` `outstanding` | Split "owe now" from "coming up" | No |
| F-18 | Medium | Risk (code) | Paid-through | Paid-through uses the earliest open due of **any** type, so one unpaid ৳20 late fee for March shows "paid through February" even when every deposit is paid | `PaidThroughCalculator.php:26-48` | One written definition, applied everywhere (portal, preview, receipt, SMS) | No |
| F-19 | Medium | Risk (code) | Collect preview | Preview ignores the member's other **pending** payments; allocation happens at approval, so the months shown at recording can differ from the final result | `PaymentPreviewer.php`, `ApprovePayment.php` | Label "expected, final on approval"; subtract pending payments; show differences in the approval dialog | Maybe (store expected allocation) |
| F-20 | Low | Confirmed (code) | Status labels | Waived and cancelled dues share the same grey badge | `DueStatus::getColor()` | Distinct colour **and** icon/text | No |
| F-21 | Low | Design note | Accounting | Dues are booked when paid (cash basis); account 1201 Dues Receivable exists but is unused, so "you owe" is a sub-ledger figure, not a GL balance | `DuePosting.php`, `AccountCode::DUES_RECEIVABLE` unused | Keep cash basis; add per-member tie-out checks (savings = 2101, advance = 2111, billed − paid − waived = outstanding) | No |
| F-22 | Low | Confirmed (code) | Locale | App default locale is `en` (`config/app.php:83`, unless `APP_LOCALE` is set) | config | Default to `bn` for members | No |

Already documented in `docs/known-gaps-and-risks.md` and not repeated here: single-person actions (#1), missing reversal flows for exits/year-end (#2), retroactive rate corrections (#5), refundable share capital on exit (#7), dashboard vs report counting dates (#13), reactivated-member dues (#14).

---

## H. Recommended roadmap

* **Phase 1 — financial data integrity:** write the 14 scenarios + new nightly checks as tests first; F-02/F-03 receipt snapshot; F-06/F-15 re-apply advance after any reversal; F-16 one eligibility rule; F-05 duplicate-cash reason; F-12 clear error.
* **Phase 2 — collection workflow:** F-18 one paid-through definition (shared read model); F-19 expected-allocation preview; F-01 dues start month with catch-up dues (after committee decision); F-08 collect-page layout and per-month grouping; F-13 "received, awaiting approval" SMS.
* **Phase 3 — member experience:** F-17 owe-now vs coming-up; F-20 status badges; F-22 Bangla default; F-04 home summary + month strip; F-07 dues/receipts pages; plain-Bangla wording pass; phone test (F-14).
* **Phase 4 — admin accounting & reports:** F-10 "this month" strip; F-09 waiver policy; reconcile dashboard vs report counting rules (known gap #13).
* **Phase 5 — maintainability:** F-11 single coverage service; turn the audit scenarios into permanent tests.

---

## I. Testing checklist

The scenario file used for this audit was removed from the repo after the run; a copy is at `/private/tmp/claude-502/-Users-fazlerabbi-Desktop-projects-TONMY-SOFT-somobay-somiti/34b24d06-de91-403f-a2bc-78a355e7328a/scratchpad/AuditScenariosTest.php` (scratch space, may be cleared on reboot). To re-run it, copy it to `tests/Feature/AuditScratch/`. Recommended permanent tests:

1. Late joiner with dues start month = society start: Jan–May dues created at join, ৳5,000 total, paid ৳3,000 → Jan–Mar settled, Apr–May open, ৳2,000 owed; share-month dividend weight starts at the join month.
2. Receipt printed at approval and reprinted after two more payments shows identical before/after/paid-through text.
3. Advance-refund reversal with open dues: dues settle from the returned advance in the same transaction; `assertBooksTieOut()`.
4. Duplicate same-day cash without a reason is refused; with a reason it is recorded and flagged on the approval queue.
5. Approval with no rate plan raises `DomainRuleViolation`, not an error page.
6. Two different payments for the same member approved concurrently: allocations never exceed dues; advance correct.
7. Member home: owes/paid/advance shown; monthly amount equals deposit + service charge × shares.
8. Member dues page lists settled months by default; partly-paid month shows the remainder.
9. Phone layout at 360 px: no horizontal scroll on home, dues, receipts.

---

## Decisions taken (2026-11-22)

* **Back-dues:** a late joiner pays deposit and service charge for every month from the society's start, each at that month's rate; registration fee once per share at joining.
* **No late fines on back months.**
* **Entry premium (option A):** a percentage of the back-dues total, set in Financial settings (maker-checker), posted to a new income account (proposed 4141 Entry Premium Income), never to member savings. Back months earn no dividend; dividends stay weighted by actual share-months.
* This resolves questions 1, 2, 5 and 7 below for F-01. Migration needed: `members.dues_start_month`, a due-origin marker, the premium setting, account 4141, and a new due type or origin for the premium.

## Questions needing a business decision

1. Must a late joiner pay every month from the society's start, or from a month the committee chooses per member? Is there an entry fee instead in some cases?
2. Should a late joiner's catch-up deposits count toward dividends for the earlier months? (Recommended: no; dividends follow actual membership months.)
3. Can the committee waive a monthly deposit or service charge (e.g. hardship), and who must approve?
4. Should the member get an SMS when a payment is recorded (pending), or only when approved?
5. Do back-dues attract late fees from the day the member signs up? (Today the 01:00 late-fee run would charge every back month at once, because it counts from each due's date. Back-dues need either an exemption or a fresh grace window.)
6. Default dues start: the go-live month (avoids clashing with imported opening arrears) or a new "society start" setting? Once confirmed, can it be changed, and by whom?
7. Do back months also carry service charge and registration fee, and at which month's rate?

Note: there is only **one** member web UI. The Filament member panel *is* `/portal` (`MemberPanelProvider.php`); the Livewire portal described in the spec was never built. The second member channel is the mobile JSON API (`routes/api.php`), and both read `MemberSummary`, so fixes there reach both.

---

## Multi-angle review (7 independent reviewers)

Seven reviewers, each with a different focus (design, code accuracy, scope, a first-time reader's view, implementation readiness, structure, and inventive alternatives), checked this plan against the code without seeing this conversation. Their main conclusions:

* **Late-joiner back-dues are the only real money gap.** Every reviewer rejected backdating shares because it would inflate dividends. The agreed design: a `dues_start_month` on the member, plus a back-dues Action with its own preview that prices each back month at that month's own rate plan and never touches share lots.
* **One "member position" read model** should feed the collect preview, receipt, portal, API, SMS and dashboard, with one definition of "paid through". That fixes F-11, F-17 and F-18 together.
* **Reconciliation is a gate, not the last step.** Write the 14 scenarios and the new nightly checks as failing tests *before* building (stranded advance, dues completeness, per-member tie-outs, receipt "after" figures, possible duplicate cash).
* **Out of scope:** a new Livewire portal, rebuilding allocation, a payment "edit" flow (corrections stay as cancel/reverse + re-record), and retroactive rate corrections.
* **Definitions to settle in the scenario table:** "partial" vs "under-payment", whether "correcting a payment" is pending (cancel) or approved (reverse + re-record, needing a third person), which of the three duplicate cases is meant, whether "two collectors" means recording or approving, and what counts as one "step".

## Status after the 2026-11 build (branch `feature/member-balances-back-dues`)

Design: `docs/superpowers/specs/2026-11-22-member-balances-back-dues-receipts-design.md`. The hand-calculated scenarios now live in `tests/Feature/Contributions/AuditScenariosTest.php`. S2, S10 and S14 assert the fixed behaviour, and S9b covers F-15.

| Finding | Status | Where |
|---|---|---|
| F-01 late joiner | Fixed: `members.dues_start_month`, back-dues at that month's rate, registration fee once, entry premium (setting, account 4141), no late fines, cancel by a second person | `BackDuesPlanner`, `BackDuesWriter`, `CreateMember`, `CancelBackDues`, `tests/Feature/Members/BackDuesTest.php` |
| F-02, F-03 receipt | Fixed: owed before/after, other owed, advance and paid-through frozen at approval (DB trigger); receipt prints them | `ApprovePayment`, `receipt.blade.php`, `ReceiptFiguresTest` |
| F-04, F-17, F-18 member home | Fixed: one `MemberPosition`; "owe now" vs "coming up"; paid-through counts deposit + service charge only; new home with three figures and a month strip | `MemberPosition`, `MemberPositionWidget` |
| F-05 duplicate cash | Fixed: a written reason is required and shown to the approver | `RecordPayment`, `CollectPayment`, `PaymentActions::approve` |
| F-06, F-15 stranded advance | Fixed: both reversal Actions re-apply advance; nightly `StrandedAdvance` check | `ReversePayment`, `ReverseAdvanceRefund` |
| F-07 receipts months | Partly: "For months" column on staff and member payment lists. The dues page default filter is unchanged | `PaymentMonths` |
| F-08 collect layout | Partly: duplicate reason and pending-aware preview; the layout itself is unchanged | — |
| F-09 waivers | Open (committee decision) | — |
| F-10 admin "this month" strip | Open | — |
| F-11 two advance-months calculations | Fixed for members (`MemberPosition::advanceCoversMonthsOf`); the collect preview keeps its own | — |
| F-12 null rate plan | Fixed: `members.errors.no_rate_plan` | `ApprovePayment` |
| F-13 recorded confirmation | Fixed: `payment_recorded` SMS when staff record cash/bank | `RecordPayment`, migration `2026_11_23_000005` |
| F-14 phone layout | Open (needs a device test) | — |
| F-16 eligibility | Fixed: `ApplyAdvance` settles any open due, oldest first, like approval | `ApplyAdvance` |
| F-19 preview | Fixed: other pending payments counted first; the approval dialog states what approving now settles | `PaymentPreviewer`, `PaymentMonths::onApproval` |
| F-20 status badges | Fixed: waived (info, gift) and cancelled (grey, x) differ in colour and icon | `DueStatus` |
| F-21 cash basis | Kept; new checks `DuesCompleteness` and `ReceiptFigures` | `app/Domain/Integrity/Checks` |
| F-22 locale | Open | — |
