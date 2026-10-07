# Somiti Manager — Master Build Specification for an AI Coding Agent (Laravel 13 + Filament 5, Web Only)

Build it as a Laravel 13 + Filament 5 modular monolith. Three rules sit at its core: every rate is an effective-dated, approved and immutable version, and each due copies a snapshot of the rate it used; all money is integer poisha in BIGINT columns, run through one Money value object and one allocation algorithm; every write goes through an Action class behind a tiered confirmation modal. This document is the master prompt. Save it as `SOMITI_SPEC.md` in the repo root and feed it to Claude Code one phase-step at a time.

## TL;DR

- **Architecture:** Laravel 13 (it requires PHP 8.3+), Filament 5 for staff and a Livewire 4 + Tailwind member portal, organised into domain modules.\[1\] The core is a double-entry ledger where entries are never edited, only reversed.
  - Rates (share unit, service charge, registration fee, late fee, due day, grace days) live in versioned `rate_plans` that start in a given month and are approved by a second person.
  - Dues copy the rate they were built from, so a rate change never alters a past month.
- **Advance payments:** money paid ahead is held as a wallet in liability account 2111 Member Advance and auto-applied, oldest due first, whenever dues are generated.
  - Recommended default: apply the advance at whatever rate is current when the due is generated, and any shortfall becomes due.
  - An optional "lock prepaid months" policy creates the future dues at payment time with the old rate frozen.
  - "Paid through month X" is calculated, never stored.
- **Penny accuracy and UX:** no floats anywhere; percentages are stored in basis points; rounding happens only at named points; every split uses a largest-remainder allocator.
  - Database constraints, invariant tests and a nightly integrity job prove that the trial balance and every sub-ledger tie out to the poisha.
  - Every create/edit/delete/approve/reverse shows a Filament confirmation, scaled by risk: a simple confirm, a confirm with a diff of changes, or typed confirmation for financial and destructive actions.

---

## 0. How to Use This Document (for Ronto)

1. Save this file as `SOMITI_SPEC.md`. Save Section 3 again as `CLAUDE.md`, because Claude Code reads that file automatically on every session.
2. Run the steps in Section 10 **in order**: paste one step's prompt, let the agent finish, check its acceptance criteria and tests, then move on. Never paste two steps at once.
3. After each step, `composer test && composer analyse && composer format:check` must be green. Commit with a message like `P2.S1: rate plan versioning`.
4. If the agent drifts (logic inside a Filament resource, floats, editing a posted voucher), reply: "Re-read SOMITI_SPEC.md §3 and §6 and fix the violation."

**Version notes (checked October 2026):**
- Laravel 13 was released on 17 March 2026 and requires PHP 8.3 at minimum.
  - It supports PHP 8.3–8.5, with bug fixes until Q3 2027 and security fixes until 17 March 2028.
  - Laravel News reported it had reached 13.33 by 22 September 2026.
- Filament's latest release is v5.9.0 (26 September 2026). Dan Harrin's v5 announcement (16 January 2026) says: "Apart from Livewire v4 support, Filament v5 has no additional changes over v4, and we'll continue pushing features to both versions."
- So Filament 4.x documentation also applies to v5, apart from Livewire-specific details.
- Pin exact versions in `composer.json` on day one.

---

## 1. Business Case

### 1.1 Problem
Bangladesh's cooperative sector is large: the International Co-operative Alliance's coops4dev mapping (reference year 2019–2020) counts 192,020 cooperatives with 11,509,825 members. Many small somitis (সমবায় সমিতি) still keep their books in a khata, Excel or WhatsApp photos. The typical failures are:
- Monthly kisti collection is tracked by hand, so late fees are argued over and "who paid which month" is unclear.
- When the committee raises the share amount (৳500 → ৳600), old months get silently recalculated or disputed.
- Advance payments (৩/৬ মাসের অগ্রিম) are forgotten or double-counted.
- Cash, bank, bKash and Nagad balances never reconcile, so the cashier's honesty becomes a social question rather than a data fact.
- Year-end profit distribution (লভ্যাংশ বণ্টন) is done on a calculator; statutory deductions are missed and rounding leaves taka unexplained.
- Audit (নিরীক্ষা) preparation takes weeks.

### 1.2 Goals
| # | Goal | Measure |
|---|------|---------|
| G1 | Every poisha is traceable from receipt to ledger to report | Trial balance difference = 0; control accounts = sub-ledgers, checked nightly |
| G2 | Rates are configurable and history-safe | A rate change never alters any earlier month's due, receipt or report |
| G3 | Members trust the numbers | Portal shows dues, payments, advance and "paid through" in Bangla |
| G4 | Committee control | Maker-checker on payments, rate changes, reversals, year-end and exits |
| G5 | Audit-ready | One-click registers: member ledger, cash book, trial balance, balance sheet, income statement, share register |
| G6 | Fast, friendly UI | Icon actions, confirmations, instant search, Bangla/English, mobile-responsive |

### 1.3 Actors (ব্যবহারকারী)
| Role | Bangla | Main powers |
|------|--------|-------------|
| super_admin | সুপার এডমিন | Settings, users, backups; cannot approve their own financial entries |
| president | সভাপতি | Approves rate changes, year-end, exits, large expenses |
| secretary | সম্পাদক | Members, meetings, resolutions; co-approves rate changes |
| cashier | ক্যাশিয়ার | Records collections (maker), bank deposits |
| accountant | হিসাবরক্ষক | Approves payments (checker), vouchers, reconciliation, reports |
| auditor | নিরীক্ষক | Read-only on everything, including the audit log |
| member | সদস্য | Portal: own ledger, dues, receipts, submit bKash/Nagad TrxID |

### 1.4 Key user stories
- As a **cashier**, I collect ৳1,800 for 3 months; the system allocates it to the oldest dues first and prints a receipt.
- As a **member**, I pay 6 months ahead via bKash and see "Paid through: ডিসেম্বর ২০২৬".
- As a **president**, I preview how many members and taka a new rate (৳600/share from January) affects before approving it.
- As an **accountant**, I reverse a wrongly approved payment with a recorded reason, without deleting anything.
- As a **secretary**, I add shares for a member effective next month, and the registration fee for the new shares is charged automatically.
- As a **member** leaving, my settlement refunds deposits, unused advance and dividend, minus what I owe.

### 1.5 Business rules (canonical)
**Membership & shares**
- BR-1: Share-count changes take effect from a stated month and never retroactively alter months already generated.
- BR-2: The **share unit amount** is the monthly savings deposit per share. Monthly deposit due = shares × share unit amount for that month.
- BR-3: The **service charge** is per share per month (may be 0) and is income (4111).
- BR-4: The **registration fee** is charged **once per share at acquisition**, at the rate effective in the month the share becomes effective.
  - A later rate change never re-charges existing shares; newly added shares pay the fee in force in their effective month.
  - Optional policy `registration_fee_on_rate_increase = none|difference` (default `none`); with `difference`, a one-time top-up due per existing share is created when an approved plan raises the fee.

**Rates**
- BR-5: Exactly one approved plan applies to any month: the latest approved plan with `effective_from ≤ month`.
- BR-6: Approved plans are immutable; a correction is a new version. A plan may be cancelled only while no due references it.
- BR-7: A plan starting in an already-generated month is rejected, except a "retroactive correction" approved by president + secretary, which creates adjustment dues and never edits old ones.

**Dues & late fees**
- BR-8: Dues are generated monthly and idempotently, one row per (member, month, type, share-lot).
- BR-9: Each due stores a full **rate snapshot** (plan id, unit amounts, late-fee rule) and the computed amount.
- BR-10: Due date = due_day of the month; late from due_date + grace_days.
- BR-11: Late fee is `fixed` (poisha) or `percent` (basis points) of a base (`deposit_only`, `deposit_plus_service`, `outstanding_total`), with an optional cap and frequency (`once` or `monthly_until_paid`). It is a separate `late_fee` due linked to its parent due.

**Collections & advance**
- BR-12: Payments go `pending → approved | rejected`; only approved payments post. Approver ≠ creator.
- BR-13: An approved payment is allocated to open dues oldest first; within a month the order is late_fee → service_charge → registration → deposit (configurable). Any remainder goes to Member Advance (2111).
- BR-14: When dues are generated, the advance balance is auto-applied, oldest open due first.
- BR-15: Advance-vs-rate-change policy is configurable (§5.4); the default is `apply_at_current_rate`.
- BR-16: Advance is refundable on exit, or on approved request (PV: Dr 2111 / Cr Cash).

**Accounting**
- BR-17: Double entry everywhere. Posted journals are immutable and corrected only by reversal plus a new journal.
- BR-18: Voucher numbers `RV/PV/JV/CV-{FY}-{000001}` are gap-free per type per fiscal year, assigned under `lockForUpdate`.
- BR-19: Fiscal year is 1 July – 30 June; closed years and periods reject postings.

**Year-end (Cooperative Societies Act 2001, s.34)**
- BR-20: From net profit (নীট মুনাফা) the Act requires:
  - at least 15% to the Reserve Fund (সংরক্ষিত তহবিল, ন্যুনতম ১৫%);\[2\]
  - 3% to the Cooperative Development Fund (সমবায় উন্নয়ন তহবিলের চাঁদা ৩%);\[2\]
  - 10% to a bad/doubtful-debt fund for financing societies;\[2\]
  - at most 10% for other bylaw purposes;\[2\]
  - the remainder is distributed as dividend (লভ্যাংশ).\[2\]
  - Section 34(4) requires 50% of the profit to be adjusted against previous losses (if any) before distribution.\[2\]
  - Store these as configurable basis points with the legal minimums and maximums enforced.
- BR-21: Dividend is distributed by **share-months** using the largest-remainder method; the lines sum exactly to the distributable amount.

**Exit**
- BR-22: Settlement = deposits + unused advance + unpaid dividend + refundable share capital − receivables − exit fee. It posts to 2301, then pays out by PV after approval.

### 1.6 End-to-end workflows
- **W1 Onboarding:**
  1. The secretary enters the member (Bangla/English name, NID, mobile, photo), nominees (shares summing to 100%), N shares and effective month M.
  2. The confirmation modal shows a summary.
  3. The system creates a share-lot and a registration-fee due (N × fee@M), sends a welcome SMS and creates the portal login.
- **W1a Self-registration.** The secretary (or anyone with `members.create`) invites a member with
  only a mobile and a password. The member signs in on the app or `/portal`, fills in their details
  and nominees (at least one, NID required, relation from the managed list), and submits. The
  registration passes the approval order set in Settings (default Secretary → President); approvers
  can approve, send it back for correction or reject it. The last approval runs W1 (member number,
  share lot, registration fee, welcome SMS). Design: `docs/superpowers/specs/2026-10-07-member-self-registration-design.md`.
- **W2 Monthly cycle (1st of month, 00:30 Asia/Dhaka, or manual):**
  1. Resolve the plan.
  2. Create deposit and service-charge dues with snapshots, idempotently.
  3. Auto-apply advance (Dr 2111 / Cr 2101, 4111…).
  4. Queue the SMS "এই মাসের কিস্তি ৳X, শেষ তারিখ Y".
- **W3 Collection:**
  1. The cashier records cash, or the member submits a bKash/Nagad TrxID + screenshot → pending.
  2. The accountant approves in a modal that shows the allocation preview.
  3. The system allocates, posts the RV, creates the receipt PDF and sends an SMS.
  4. A rejection needs a reason.
- **W4 Advance:** as W3; the preview shows "৳X to current dues, ৳Y held as advance → covers through month Z at current rates (estimate)".
- **W5 Late fee (daily 01:00):** create or extend late-fee dues for unpaid dues past grace, idempotently, then auto-apply advance.
- **W6 Rate change:** draft plan → impact preview (§5.3) → submit → president + secretary approve → future months use it; locked prepaid dues are handled per §5.4.
- **W7 Investment (Phase 10):** resolution → PV (Dr 13xx / Cr Bank) → profit RV (Cr 4201) → closure.
- **W8 Year-end (Phase 11):** lock → adjustments → net profit → statutory appropriation preview → AGM resolution → approve → closing JV (Cr 3201, Cr 2211, Cr 2201 per member) → new FY → dividend payout or credit to savings.
- **W9 Exit (Phase 12):** request → freeze dues → settlement preview → approval → JV to 2301 → PV payout → status `exited`; history kept forever.

### 1.7 Non-functional requirements
| Area | Requirement |
|------|-------------|
| Security | Staff-only Filament panel with MFA; login rate limit; policies on every model; signed receipt URLs; proofs on a private disk |
| Audit | spatie/laravel-activitylog with old/new values and causer; append-only `financial_audit` for approvals and reversals |
| Backup | spatie/laravel-backup daily DB + files off-site, 30-day retention, monthly restore drill |
| Performance | Lists < 300 ms p95 at 5,000 members × 10 years; indexed queries; `preventLazyLoading()` outside production |
| Localization | Bangla default, English toggle, Bangla digits in UI/PDF, Asia/Dhaka, `৳ ১২,৩৪৫.৫০` |
| Retention | Financial records are never hard-deleted |

### 1.8 Success criteria
- The pilot somiti runs 3 months with no khata, zero trial-balance differences and zero unexplained poisha.
- A ৳500 → ৳600 change goes live with a correct preview and no historical report changes (snapshot test).
- The auditor prints all registers for a year in under 5 minutes.
- Member portal adoption ≥ 60% within 3 months.

---

## 2. Tech Stack
| Concern | Choice |
|--------|--------|
| Framework | Laravel 13.x, PHP 8.3+, `declare(strict_types=1);` everywhere |
| DB | MySQL 8.0+ (or PostgreSQL 15+); all money `BIGINT` |
| Queue/cache | Redis (Horizon optional) |
| Staff UI | Filament 5.x at `/admin` |
| Member UI | Livewire 4 + Tailwind 4 at `/portal` |
| Money | `brick/money` behind our own `App\Support\Money\Money` wrapper (or a pure-int implementation); the wrapper is mandatory |
| Permissions / audit | spatie/laravel-permission, spatie/laravel-activitylog |
| PDF / Excel | mPDF with Nikosh (`useOTL`, `autoScriptToLang`, `autoLangToFont`); maatwebsite/excel |
| SMS | `SmsGateway` interface; BD gateway driver + log driver |
| Language switch | bezhansalleh/filament-language-switch v5 (supports Filament v5 since 5.0.0)\[3\]\[4\] |
| Quality | Pest, Larastan level 8+, Pint, GitHub Actions |

---

## 3. Coding Standards (copy into `CLAUDE.md`)
1. **No business logic in UI.** Filament and Livewire only gather input, call one Action and show the result.
2. **Actions** are final invokable classes. Every financial Action:
   - runs in `DB::transaction(..., attempts: 3)`;
   - locks the rows it mutates (`lockForUpdate()`);
   - checks invariants before commit;
   - writes the activity log;
   - throws `DomainRuleViolation` with bn/en translation keys.
3. **DTOs** are `final readonly class`; money fields are `Money`, never int/float/string.
4. **Enums** are backed enums for every status/type, implementing Filament `HasLabel`, `HasColor`, `HasIcon`.
5. **Money:**
   - no `float`, no `round()`, no `/` on money;
   - BIGINT `_poisha` columns with `MoneyCast`;
   - percentages are INT basis points (`_bps`, 1% = 100);
   - JS never computes money.
6. **Immutability:** posted journals, approved rate plans, approved payments and due amounts cannot change. Model guards throw; add DB triggers where supported.
7. **Policies** on every model; never check roles inline in UI.
8. **Idempotency:** natural unique keys for every generator; an `idempotency_key` UUID for every externally triggered write.
9. **Time:** `CarbonImmutable`; a `YearMonth` value object stored as the 1st of the month; Asia/Dhaka for business dates.
10. **Tests:** every Action covers the happy path, each rule violation and concurrency where relevant; money algorithms get invariant tests.
11. Larastan level 8 with no baseline growth; Pint on commit.
12. English code; Bangla only in `lang/bn`; all strings through `__()`.
13. Forward-only migrations in production.
14. No hard deletes of anything with financial impact (§7.4).

---

## 4. Project Structure
```
app/Domain/
  Accounting/   Actions(PostJournal, ReverseJournal, NextVoucherNumber) Models(Account, JournalEntry, JournalLine, FiscalYear, Period) Services(TrialBalance, LedgerQuery, Reconciliation)
  Settings/     Actions(DraftRatePlan, SubmitRatePlan, ApproveRatePlan, CancelRatePlan) Models(RatePlan, RatePlanApproval, SomitiProfile) Services(RateResolver, RateImpactPreviewer)
  Members/      Actions(CreateMember, UpdateMember, ChangeShares, DeactivateMember) Models(Member, Nominee, ShareLot, ShareTransaction, MemberShareSnapshot)
  Contributions/ Actions(GenerateMonthlyDues, ApplyLateFees, RecordPayment, ApprovePayment, RejectPayment, ReversePayment, ApplyAdvance, RefundAdvance) Models(Due, Payment, PaymentAllocation, AdvanceLedgerEntry) Services(AllocationEngine, PaidThroughCalculator, LateFeeCalculator)
  Investments/ YearEnd/ Exits/ Governance/ Notifications/
  Integrity/    Checks(TrialBalance, ControlAccount, AllocationSum, DueSnapshot, VoucherSequence) Jobs(NightlyIntegrityJob)
app/Support/ Money(Money, MoneyCast, Allocator, Rounding, Bps) Time(YearMonth) Bangla(BanglaNumber, BanglaDate)
app/Filament/ Resources(thin) Pages Widgets Concerns(ConfirmsWithTier) Navigation(NavGroup)
app/Livewire/Portal/   app/Policies/   lang/bn, lang/en
tests/Unit Feature Invariants Concurrency
```

---

## 5. Configurable, Time-Versioned Rates (Requirement 1)

### 5.1 Principle
This follows the billing-industry pattern. Stripe's documentation says that to change a price you "create a new price for the new amount, then archive the existing price", so that "we keep the existing price as an immutable record of past transactions."\[5\]\[6\] We do the same thing:
- `rate_plans` rows are versions; approved versions are never edited.
- Each due copies the values it used, so reports never re-derive old amounts from current settings.

### 5.2 Schema
```
rate_plans: id, code, effective_from DATE(1st), status(draft|pending_approval|approved|cancelled|superseded),
  share_unit_poisha, service_charge_per_share_poisha, registration_fee_per_share_poisha  (BIGINT UNSIGNED)
  due_day TINYINT(1–28), grace_days SMALLINT,
  late_fee_mode(none|fixed|percent), late_fee_fixed_poisha, late_fee_bps INT, late_fee_base, late_fee_cap_poisha, late_fee_frequency,
  advance_policy(apply_at_current_rate|lock_prepaid_months), registration_fee_on_rate_increase(none|difference),
  allocation_order JSON, notes, created_by, submitted_at, approved_at, cancelled_reason
  -- one approved plan per effective_from (enforced in Action; PostgreSQL partial unique index)
rate_plan_approvals: rate_plan_id, user_id, role, decision, comment  UNIQUE(rate_plan_id, user_id)
```
`RateResolver::for(YearMonth)` returns the approved plan with the latest `effective_from ≤ month`, or throws `NoRatePlanForMonth`.

### 5.3 Impact preview (`RateImpactPreviewer`)
Shown in the Submit and Approve modals, with an Excel download:
- months affected; active members and shares affected;
- monthly collection old vs new and the difference;
- members with advance and their coverage (months) old vs new, plus the shortfall;
- locked prepaid dues affected (lock policy only);
- registration-fee impact (`difference` policy only);
- warnings: month already generated, percent fee without cap, etc.

### 5.4 Advance vs rate change — policy (Requirement 2)
| Policy | Behaviour | Pros | Cons |
|--------|-----------|------|------|
| **`apply_at_current_rate` (DEFAULT)** | The advance is a money balance in 2111. Each month's dues use that month's rate and draw the advance down; if ৳600 is due and ৳400 is left, ৳400 is applied and ৳200 stays due | Simple and auditable. It matches how payment platforms handle customer credit: Stripe's API documentation says each customer's balance is a debit or credit "that's automatically applied to their next invoice upon finalization." Everyone pays the same rate in the same month | "6 months" may become "5 months"; mitigated by preview + SMS |
| `lock_prepaid_months` | On approval, the system pre-generates the covered future dues at the current plan and allocates immediately; later rate changes don't touch them | Price protection; matches "I paid for 6 months" | Unequal savings between members, which also skews share-month dividends; needs a bylaw/AGM decision |

Why the default: in a savings society the deposit is the member's own savings, not a price. Locking old rates creates unequal savings balances. Each payment stores `advance_policy_at_payment`, so behaviour is reproducible.

**Edge cases the agent must implement:**
- Partial advance (৳700 at ৳500/month): one month is covered and ৳200 is applied to the next due.
- Rate decrease: under the default the advance covers more months; locked dues are repriced only via `RepriceLockedDues` (approval required).
- A share increase while dues are locked: dues for the new share-lot are generated normally.
- Exit or refund: the unused 2111 balance is refunded. Locked dues after the exit month are un-allocated (Dr 2101 / Cr 2111), cancelled and refunded.
- Reversal after the advance was applied: allocations are reversed newest first and dues reopen. If 2111 would go negative, dependent allocations are reversed in the same transaction.

### 5.5 Journal entries (every member line carries `member_id`)
Default recognition: deposits and fees are recognised **when settled**. Dues are an obligation sub-ledger (`accrue_due_income=false`); setting it true posts Dr 1201 on generation.

| Event | Debit | Credit |
|-------|-------|--------|
| Payment to current dues (৳1,000 deposit + ৳20 service) | 1101/1121 ৳1,020 | 2101 ৳1,000; 4111 ৳20 |
| Advance portion | 1101/1121 | 2111 |
| Advance auto-applied to dues | 2111 | 2101 / 4111 / 4101 / 4121 by due type |
| Advance refund (PV) | 2111 | 1101/1111 |
| Reversal | mirror of the original lines (`reverses_id`) | |

"Paid through" = the latest month M with every due ≤ M fully settled. With a remaining advance, the portal also shows "আনুমানিক আরও N মাস (বর্তমান হারে)", labelled as an estimate.

---

## 6. Penny-Accurate Accounting (Requirement 3)

### 6.1 Representation
- ৳1 = 100 poisha. Every amount is a PHP `int` inside `Money` and a `BIGINT` column.
- `Money` API: `ofPoisha`, `ofTaka('1234.50')` (string parse, max 2 decimals), `plus`, `minus`, `multipliedByInt`, `percentOfBps`, `allocate`, `compare`, `format(locale)`.
- If backed by brick/money: it makes arithmetic exact and requires explicit rounding. Operations that need rounding throw `RoundingNecessaryException` unless a rounding mode is passed.\[7\]\[8\]
  - Its allocation API changed in 0.13.0 (2026-03-28), so keep it behind our wrapper and pin the version.\[9\]
- Forbidden (Pest arch tests): `float` in `app/Domain`; `round/floor/ceil/number_format` on money; DECIMAL/FLOAT/DOUBLE money columns.
- The Filament `MoneyInput` accepts "1,234.50" or Bangla digits and sends a string, which the server parses to poisha.

### 6.2 Rounding policy (only at named points)
| Point | Rule |
|-------|------|
| Percentage late fee | `intdiv(base × bps + 5000, 10000)` = HALF_UP to the poisha, then apply the cap |
| Statutory appropriation | Each line HALF_UP; the dividend pool absorbs the remainder, so the lines sum exactly to net profit |
| Dividend per member, any split | Largest remainder (§6.3) |
| Display | Never rounds |
| Optional cash rounding on payout | Difference posted to a rounding account; off by default |

HALF_UP is chosen over banker's rounding because single fees are checked by humans. Wherever amounts are split, the allocator guarantees exact totals anyway.

### 6.3 Largest-remainder allocation
This is the textbook answer to "Foemmel's conundrum", from Martin Fowler's *Patterns of Enterprise Application Architecture*: allocate 5 cents 70/30 without gaining or losing a cent. The answer is an `allocate` method on Money rather than multiplying and rounding.\[10\]\[11\]
```
q[i] = intdiv(T × w[i], Σw); r[i] = (T × w[i]) mod Σw      // brick/math if T×w may overflow
leftover = T − Σq   → +1 poisha to the `leftover` largest r[i]; ties → lower key
assert Σ = T
```
Used for dividends (weights = share-months), nominee splits and proportional splits.

### 6.4 Payment allocation
1. Lock the member row, then the open dues (`ORDER BY month, priority FOR UPDATE`).
2. Apply `min(remaining, outstanding)` to each due in turn.
3. Put the remainder into the advance.
4. Assert Σ allocations + advance = payment.
5. Write `payment_allocations` with UNIQUE(payment_id, due_id).
6. Post one journal and commit.

### 6.5 Database constraints
- `journal_lines`: unsigned BIGINT `debit_poisha` and `credit_poisha`, with `CHECK ((debit_poisha>0 AND credit_poisha=0) OR (debit_poisha=0 AND credit_poisha>0))`; foreign keys `ON DELETE RESTRICT`.\[12\]\[13\]
- A balanced entry spans rows, which a plain CHECK can't see.\[13\]\[14\]
  - PostgreSQL: a `DEFERRABLE INITIALLY DEFERRED` constraint trigger rejects unbalanced entries at commit.\[13\]\[15\]\[16\]
  - MySQL: enforce in `PostJournal`, run a nightly check, and add a trigger that blocks UPDATE/DELETE on posted lines.
- `dues`: UNIQUE(member_id, month, type, share_lot_id, adjustment_seq); `CHECK (paid_poisha <= amount_poisha)`; generated `outstanding_poisha`.
- `payments`: UNIQUE(method, trx_id) (duplicate TrxID guard); UNIQUE(idempotency_key).
- `voucher_sequences`: PK(fiscal_year_id, type), row-locked.
- `advance_ledger_entries`: signed delta, `balance_after_poisha` with CHECK ≥ 0.

### 6.6 Concurrency
- Use a fixed lock order everywhere: member → dues → advance ledger → voucher sequence.
- Approval is an atomic conditional update (`WHERE status='pending'`); 0 rows affected → "already processed".
- Dues generation uses chunked jobs, `insertOrIgnore` on the unique key and `Cache::lock('dues:{month}')`.
- `tests/Concurrency` uses two DB connections to prove a double approval produces exactly one journal.

### 6.7 Invariants (code, tests, nightly)
1. Σ debits = Σ credits, per entry and in total.
2. Control accounts tie out: GL 2101 = Σ member savings; 2111 = Σ advance balances; 2201 = Σ unpaid dividends; 2301 = Σ open exits.
3. For each payment, Σ allocations + advance = amount.
4. For each due, paid = Σ its allocations, and 0 ≤ paid ≤ amount.
5. Σ dividend lines = pool; Σ appropriation lines = net profit.
6. Voucher numbers are gap-free and unique per (FY, type).
7. Every due's snapshot matches its rate plan.
8. No posted journal is modified after posting; an optional SHA-256 hash chain verifies this.

`NightlyIntegrityJob` (02:00) stores `integrity_runs` and findings, shows a red dashboard banner, and emails/SMSes the admin and accountant on any failure. Property tests run 1,000 random seeds (members, rate changes, payments, advances, reversals, late fees) and assert all invariants, printing the failing seed.

---

## 7. Confirmation for Every Action (Requirement 4)

### 7.1 Tiered confirmation
Every write is confirmed, with friction scaled to risk. This matters because confirmation dialogs backfire if they all look the same. Jakob Nielsen's NN/G article "Confirmation Dialogs Can Prevent User Errors — If Not Overused" (2018, last reviewed August 2026) says: "Do not use confirmation dialogs for routine actions". It adds that "the most important usability considerations in confirmation dialogs is to not overuse them and to be sufficiently specific that users know what they're agreeing to." The same article notes that Don Norman "goes so far as to suggest requiring a different user to confirm the most dangerous actions", which is the basis for T3 plus maker-checker.

| Tier | Used for | UI |
|------|----------|----|
| T1 Simple | Non-financial create/edit (nominee, SMS template, meeting) | `requiresConfirmation()` with a specific heading ("সদস্য 'রহিম উদ্দিন' সংরক্ষণ করবেন?") |
| T2 Diff/summary | Member edits, settings, share changes, payment entry | Modal with an old → new **diff table** or an allocation summary |
| T3 Typed | Approve/reject/reverse payments, post/reverse vouchers, approve rate plans, year-end, exit, bulk actions, deactivation | Summary + a field where the user types the voucher no/member no/`নিশ্চিত`; the submit label names the consequence ("৳১,৮০০ অনুমোদন করুন") |

### 7.2 Filament implementation
- `Action::make('approve')->requiresConfirmation()->modalHeading(...)->modalDescription(...)->modalSubmitActionLabel(...)->modalIcon(...)->modalIconColor('success')`.
  - `requiresConfirmation()` sets its own default description, so call `modalDescription()` **after** it.\[17\]\[18\]
  - The docs warn that the confirmation modal isn't available when `url()` is set instead of `action()`, so destructive and financial actions must always use `action()`.\[19\]
- Typed confirmation: `->schema([TextInput::make('confirm_text')->required()->in([$expected])])`. Laravel's `in` rule blocks submission unless the text matches exactly.
- Create/Edit pages: override `getCreateFormAction()` / `getSaveFormAction()` with `requiresConfirmation()`, plus `modalContent()` rendering a diff from a `ChangeSummary` service that formats money and enums in Bangla.
- Bulk actions: `requiresConfirmation()` with a description that shows the count and up to 10 record names.
- Panel: `->unsavedChangesAlerts()` (covers Create/Edit pages and open action modals) and `->databaseTransactions()` (Filament doesn't wrap operations in transactions by default).\[20\] Our Actions still manage their own transactions.
- A shared trait `ConfirmsWithTier` (`tier1()`, `tier2($diff)`, `tier3($expected)`) keeps every resource consistent.

### 7.3 Feedback
- Success: `Notification::make()->title(...)->success()->send()` with the voucher/receipt number and a "View receipt" action.\[21\]\[22\]
- Domain errors: a translated `->danger()` notification; never a stack trace.
- Long jobs: `->sendToDatabase($user)` when finished (requires running queue workers).\[23\]\[24\]

### 7.4 Delete policy
| Record | Delete? | Instead |
|--------|---------|---------|
| Posted journals, approved payments, allocations, paid dues, approved rate plans, dividend lines | **Never** | Reverse / cancel with reason |
| Members | Never hard-delete | Deactivate; soft delete only if there are no financial rows |
| Pending payment | No | Creator cancels (status `cancelled`) |
| Draft plan/voucher, unused account | Soft delete (T3) if unreferenced | `forceDelete` disabled |
| Nominee, SMS template, draft meeting | Soft delete (T1/T2), restorable | |

The policies' `delete`/`forceDelete` return false for financial models, which hides Filament's DeleteAction automatically.

---

## 8. UI/UX Specification (Requirement 5)

### 8.1 Icon buttons
- Row actions use `->iconButton()` (circular buttons with an icon and no label) + `->tooltip(__('…'))`.\[25\]\[26\]
  - Always set a label; `iconButton()` hides it visually.
  - Use `->hiddenLabel()` where the button background should stay.\[27\]\[28\]
- Rare actions go in `ActionGroup::make([...])->iconButton()->tooltip('More')`.\[26\]
- Header primary actions use `->button()->labeledFrom('md')`: an icon on mobile, icon + label on desktop.\[25\]
- Filament 5 picks icon-button shades to meet WCAG AA non-text contrast (3:1), so use semantic colors only, not raw Tailwind classes.\[29\]

| Action | Heroicon | Color |
|--------|----------|-------|
| Create | OutlinedPlus | primary |
| View | OutlinedEye | gray |
| Edit | OutlinedPencilSquare | warning |
| Delete/Cancel | OutlinedTrash / OutlinedXCircle | danger |
| Approve | OutlinedCheckCircle | success |
| Reject | OutlinedNoSymbol | danger |
| Reverse | OutlinedArrowUturnLeft | danger |
| Print/PDF | OutlinedPrinter | info |
| Export | OutlinedArrowDownTray | gray |
| Collect payment | OutlinedBanknotes | success |

### 8.2 Navigation tree
- A `NavGroup` enum (`HasLabel`, `HasIcon`) registered with `navigationGroups()`.
- Children use `$navigationParentItem`; the parent and child must be in the same group.\[30\]
- Groups are collapsible by default.\[30\]
- `->sidebarCollapsibleOnDesktop()`: group icons become flyout dropdowns when collapsed. When a group has an icon, item icons are hidden in the expanded sidebar.\[30\]
- Use Clusters for Settings (a third level).
- Visibility comes from policies (`canViewAny`); custom items use `->visible()` and `isActiveWhen()`.
- The pending-approvals badge uses `getNavigationBadge()`.

```
ড্যাশবোর্ড Dashboard
সদস্য Members ▸ All Members · Add Member · Share Changes · Nominees
আদায় Collections ▸ Collect Payment · Pending Approvals [badge] · Advance Balances · Advance Refunds · Receipts
বকেয়া Dues ▸ Monthly Dues · Generate Dues · Late Fees · Defaulters
হিসাব Accounting ▸ Vouchers · Expenses · Fund Transfers · Chart of Accounts · Bank & Wallet Accounts · Reconciliation · Fiscal Years
বিনিয়োগ Investments ▸ Investments · Profit Receipts
বার্ষিক সমাপনী Year End ▸ Closing Wizard · Dividends · Statutory Funds
প্রত্যাহার Exits ▸ Requests · Settlements
সভা Governance ▸ Meetings · Resolutions
রিপোর্ট Reports ▸ Member Ledger · Cash Book · Receipts & Payments · Trial Balance · Income Statement · Balance Sheet · Share Register · Collection Summary · Integrity Report
সেটিংস Settings (Cluster) ▸ Somiti Info · Rates & Plans · Late Fee Rules · Payment Methods · Users & Roles · SMS Templates · Backups
অডিট লগ Audit Log
```

### 8.3 Transitions and perceived speed
- Filament: `->spa(hasPrefetching: true)`. SPA mode uses Livewire's `wire:navigate`, so the panel feels like a single-page app with a loading bar for longer requests. Prefetch on hover can raise server load on heavy pages, so add `spaUrlExceptions()` for PDF/report routes.\[20\]
- Portal: `wire:navigate` links plus `wire:transition.navigate` on the content region. This animates page changes with the browser's View Transitions API; browsers without support just swap instantly.\[31\]\[32\]
  - Keep animations to 150–200 ms and honour `prefers-reduced-motion`.
  - Livewire shows a progress bar automatically when a page takes longer than 150 ms.\[31\]
- Skeletons via `wire:loading`; `->deferLoading()` on heavy tables; `->slideOver()` for long forms.
- Use `->paginated([10, 25, 50, 100])` with no `'all'`, because Filament warns `'all'` can cause performance issues.\[33\]\[34\]

### 8.4 Table standard (`Table::configureUsing()` in a service provider)
- **Pagination:** `[10, 25, 50, 100]`, `defaultPaginationPageOption(25)`. Records-per-page is persisted in the session by default. Use `extremePaginationLinks()`.\[33\]
- **Search:** `searchable()` on member no/name/mobile/TrxID. Debounce defaults to 500 ms; set `->searchDebounce('400ms')`.\[35\]\[36\] The search field has a built-in clear (×).
- **Filters:** date range (custom from/until), status (enum `SelectFilter`), member (searchable), method, fiscal year, month.
  - Filament 4/5 defers filters by default: changes only apply after the user clicks "Apply"; `deferFilters(false)` makes them live.\[37\]\[38\]
  - Keep deferred for heavy tables. Layout `FiltersLayout::AboveContentCollapsible`;\[39\]\[40\] "Clear all" via `filtersRemoveAllAction()`.\[37\]
- **Persistence:** `persistFiltersInSession()`, `persistSortInSession()`, `persistSearchInSession()`, `persistColumnSearchesInSession()`.\[41\]\[42\] Add `queryStringIdentifier()` when two tables share a page.\[33\]
- **Sorting:** dates, amounts and names are sortable; default newest first.
- **Empty states:** `emptyStateHeading`, `emptyStateDescription`, `emptyStateIcon`, `emptyStateActions`.\[43\]
- **Export:** queued `ExportAction`/`ExportBulkAction` that respects the current filters.
- **Bulk actions:** T3, each record in its own transaction, with a result summary.
- **Money columns:** right-aligned, formatted by `Money::format()`, with SQL integer `summarize()` totals.

### 8.5 Language, digits, theme, responsive
- bezhansalleh/filament-language-switch v5; `users.locale` + middleware; a toggle in the portal header.
- `BanglaNumber::digits()` applies at display time only; lakh grouping (১২,৩৪,৫৬৭) is optional.
- Dark mode: Filament `->darkMode()`; Tailwind `dark:` in the portal.
- Responsive: `->visibleFrom('md')` / `toggleable(isToggledHiddenByDefault: true)` for secondary columns; the portal is mobile-first.
- Fonts: Hind Siliguri / Noto Sans Bengali on the web; Nikosh in mPDF with OTL for correct যুক্তাক্ষর.

---

## 9. Core Tables (MVP)
`somiti_profiles`, `users`, permission tables, `fiscal_years`, `periods`, `accounts`, `journal_entries` (voucher_type, voucher_no, fiscal_year_id, date, status, reverses_id, source, posted_by, hash), `journal_lines` (account_id, member_id, debit_poisha, credit_poisha), `voucher_sequences`, `rate_plans`, `rate_plan_approvals`, `members`, `nominees` (share_bps Σ=10000), `share_lots`, `share_transactions`, `member_share_snapshots`, `dues` (rate_plan_id, snapshot JSON, amount/paid/outstanding poisha, due_date, parent_due_id, prepaid_locked), `payments` (method, trx_id, proof, status, idempotency_key, advance_policy_at_payment, maker/checker, journal_entry_id), `payment_allocations`, `advance_ledger_entries`, `receipts`, `sms_messages`, `integrity_runs`, `integrity_findings`, `activity_log`.

---

## 10. Implementation Plan — Phases and Copy-Paste Prompts
Every prompt starts with **"Read SOMITI_SPEC.md (sections referenced) and CLAUDE.md. Do only this step."** and ends with **"Run composer test, analyse, format; summarise the files changed and the test results."**

### Phase 0 — Foundation (week 1)
**P0.S1 Bootstrap**
> Create a Laravel 13 app "somiti" (PHP 8.3+, strict types). Configure the Filament 5 panel at /admin: Asia/Dhaka, `spa(hasPrefetching: true)`, `unsavedChangesAlerts()`, `databaseTransactions()`, `sidebarCollapsibleOnDesktop()`, `databaseNotifications()`, staff MFA. Install spatie permission/activitylog/backup, maatwebsite/excel, mpdf, brick/money, filament-language-switch v5, Pest, Larastan 8, Pint; composer scripts test/analyse/format/format:check; GitHub Actions with MySQL 8 + Redis; the §4 folders; lang/bn + lang/en; CLAUDE.md from §3. Pest arch tests: no float or round/floor/ceil in app/Domain and app/Support/Money; Domain classes are final; Filament classes do not write financial models directly.

Acceptance: login works; CI green; the arch test fails on a deliberately added float (then removed).

**P0.S2 Money, YearMonth, Bangla**
> Implement §6.1–6.3: an immutable `Money` (int poisha, optionally wrapping brick/money), `MoneyCast`, `Bps`, `Rounding::halfUpBps()` in integer math, `Allocator::largestRemainder()` (deterministic ties, overflow-safe via brick/math), `YearMonth`, `BanglaNumber`, `BanglaDate`, and a Filament `MoneyInput` (English/Bangla digits, ≤2 decimals, stores poisha).

Acceptance:
- ৳0.1 + ৳0.2 = ৳0.3 exactly.
- Foemmel: 5 poisha split [7,3] gives [4,1] deterministically. The floors are [3,1]; both remainders are 0.5, so the tie goes to the lower key.
- 10,000 random allocations sum exactly, with each part within 1 poisha of its exact share.

### Phase 1 — Accounting core (week 2)
**P1.S1 Chart of accounts and fiscal years**
> Implement accounts (type, normal_balance, is_control, requires_member, active) seeded with 1101, 1111, 1121, 1122, 1201, 13xx, 2101, 2111, 2201, 2211, 2301, 3101, 3201, 3901, 4101, 4111, 4121, 4201, 5xxx. FiscalYear 1 Jul–30 Jun with 12 lockable periods. Thin Filament resources; FY close is T3.

Acceptance: an account with lines can't be deleted; the FY boundary math is tested.

**P1.S2 Journals, vouchers, reversal**
> `PostJournal` (≥2 one-sided lines, Σdr=Σcr>0, active accounts, member_id iff requires_member, open period), `NextVoucherNumber` (lockForUpdate, `RV-2026-27-000001`), `ReverseJournal` (mirror entry, reason, links both ways). Model guard + DB trigger against editing posted lines. Vouchers resource with filters, view page, Post/Reverse (T3), manual JV with repeater lines and a display-only live Σdr/Σcr.

Acceptance: unbalanced entries are rejected; 50 concurrent postings get gap-free unique numbers; a reversal nets to zero.

**P1.S3 Trial balance and ledgers**
> `TrialBalance::asOf()`, `LedgerQuery` with running balance (SQL integer sums), `Reconciliation::controlVsSubledger()`. Report pages with mPDF (Nikosh, Bangla digits) and Excel.

Acceptance: the TB balances on demo data; Bangla conjuncts render correctly in the PDF.

### Phase 2 — Time-versioned rates (week 3)
**P2.S1 Rate plans**
> Implement §5.1–5.2 with Draft/Update/Submit/Approve/Cancel Actions. Approval needs president + secretary, and approver ≠ creator. Add `RateResolver` and BR-5–7 validation. Filament Settings cluster → Rates & Plans timeline (effective_from, key rates, status, approvals); MoneyInput and a bps input shown as "2.00%"; approved plans are view-only, with "Duplicate as new version".

Acceptance: approved plans can't be edited (UI + guard); table-driven resolver tests; a retroactive plan needs double approval.

**P2.S2 Impact preview**
> Implement `RateImpactPreviewer` (§5.3) in the Submit/Approve T3 modals, with an Excel download.

Acceptance: preview → approve → generate gives the same numbers as the preview.

### Phase 3 — Members and shares (week 4)
**P3.S1 Members**
> Members (auto `M-0001`, name bn/en, NID, BD mobile validation, photo, status), nominees with share_bps Σ=10000. `CreateMember` creates the share lot and the registration-fee due at the effective month's rate. T2 summary on create, diff on edit, T3 deactivate; no hard delete.

**P3.S2 Share changes**
> `ChangeShares`: an increase adds a lot plus a registration due for the added shares at that month's fee; a decrease ends lots FIFO; maintain `member_share_snapshots`.

Acceptance: 2 shares from July + 1 from October give dues 2×fee(Jul) + 1×fee(Oct); a September rate change doesn't touch the July fee; the `difference` policy is tested.

### Phase 4 — Dues engine (week 5)
**P4.S1 Generation**
> `GenerateMonthlyDues(YearMonth)` per W2 (snapshots, unique key, chunked jobs, cache lock, then ApplyAdvance). Add the command `somiti:dues:generate {month?}`, a schedule on the 1st at 00:30, and a T3 "Generate Dues" page with a preview; report via a database notification.

**P4.S2 Late fees**
> `LateFeeCalculator` + `ApplyLateFees` per BR-10/11 (HALF_UP bps, cap, idempotent per parent due and period), daily at 01:00. A T3 "Waive late fee" action with a reason; if the fee is already paid, the credit goes to advance.

Acceptance:
- Running generation twice gives the same row count.
- 2% of ৳1,005.25 = ৳20.11 (100525 × 200 + 5000, intdiv 10000 = 2011).
- Due day 10 with 5 grace days → late from the 16th.

### Phase 5 — Collections and advance (weeks 6–7)
**P5.S1 Record payment (maker)**
> `RecordPayment`: cash/bkash/nagad/bank, MoneyInput, TrxID required and unique for non-cash, private proof upload ≤2 MB, idempotency key, status pending. A "Collect Payment" slide-over with member search, open dues, advance balance, a server-side allocation preview and T2 confirmation.

**P5.S2 Approve / reject / reverse (checker)**
> `ApprovePayment` per §6.4/§5.5: atomic transition, approver ≠ creator, allocation order from the plan, remainder to 2111, RV posting, Bangla receipt PDF (allocation, advance, paid-through) and SMS. `RejectPayment` with a reason; `ReversePayment` with the §5.4 cascade. Pending Approvals page with a badge and bulk approve (T3, per-record transactions).

**P5.S3 Advance engine and policies**
> `ApplyAdvance`, the `lock_prepaid_months` pre-generation (`prepaid_locked=true`), `RefundAdvance` (PV, T3, president above a threshold), `PaidThroughCalculator`, and an Advance Balances page.

Acceptance tests (1 share at ৳500):
1. ৳3,000 paid in July → July settled, ৳2,500 held; August–December auto-settled; paid through December.
2. Plan ৳600 from October (default policy) → October and November settled, December has ৳100 applied and ৳500 outstanding; paid through November.
3. The same under the lock policy → August–December locked at ৳500; January onward at ৳600.
4. A partial ৳700 → one month plus ৳200 applied to the next.
5. Reversing the ৳3,000 after three applications → dues reopen, 2111 = 0, TB balances.
6. Two simultaneous approvals → one journal.
7. All §6.7 invariants hold after every scenario.

### Phase 6 — Portal, SMS, reports (week 8)
**P6.S1 Member portal**
> `/portal` with mobile OTP/password login (rate limited). Pages: dashboard (savings, advance, paid-through, current due, recent payments), dues, receipts (signed-URL PDFs), submit bKash/Nagad, profile/nominees, bn/en toggle, dark mode. Use `wire:navigate`, `wire:transition.navigate` (reduced motion), skeletons and an Alpine `<x-confirm>` modal. Scoping tests prove members only see their own data.

**P6.S2 SMS**
> `SmsGateway` with log + one BD driver, a queued `SendSms` with retry and a `sms_messages` log, and Bangla templates with placeholders editable in Settings (T2 diff, unicode length counter).

**P6.S3 Reports**
> Member Ledger, Cash Book, Receipts & Payments, Collection Summary, Defaulters, Share Register, Income Statement, Balance Sheet: filterable, with PDF + Excel and SQL integer totals.

### Phase 7 — Integrity and MVP release (week 9)
**P7.S1 Integrity checks**
> Implement the §6.7 checks, NightlyIntegrityJob, an Integrity Report page, a dashboard banner and alerts. Add the 1,000-seed simulation in `tests/Invariants`.

**P7.S2 Hardening**
> Apply §8.4 table defaults globally. A test enumerates every resource action and asserts its icon, tooltip, color and confirmation tier. Add policies everywhere, enforce MFA and throttling, schedule backups, add a health route, and run a 5,000-member performance test (< 300 ms lists).

**MVP done when** Phases 0–7 are complete, all acceptance tests are green, the integrity job is clean, and the pilot committee has completed Bangla UAT.

### Later phases (each step uses the same prompt format)
| Phase | Prompt summary | Key acceptance |
|-------|----------------|----------------|
| 8 Expenses & transfers | Expenses (category → 5xxx, attachment, PV, approval threshold); fund transfers (CV); bank/wallet statement CSV import and matching | TB invariants; thresholds enforced |
| 9 Governance | Meetings (quorum), resolutions linked to rate plans, investments, year-end, exits | Linked resolution required where configured |
| 10 Investments | Register, disbursement PV, profit RV (4201), closure, impairment JV; s.33 limits as warnings (e.g. ≤10% of surplus in company securities with AGM approval)\[2\] | Register ties to GL 13xx |
| 11 Year-end | Wizard: lock → adjustments → net profit → s.34(4) prior-loss adjustment → appropriation with legal min/max in bps → AGM link → T3 president + accountant → closing JV → carry forward. Dividend by share-months via largest remainder; pay out (PV) or credit to savings (Dr 2201 / Cr 2101) | Σ lines = pool exactly; deterministic ties |
| 12 Exit | Freeze dues → BR-22 settlement (incl. unlocking prepaid dues) → approval → JV 2301 → PV; nominee split by share_bps | The member's sub-ledgers are zero after exit |
| 13 Deployment | Ubuntu 24.04, Nginx, PHP-FPM 8.4, MySQL 8, Redis, Supervisor (queue + scheduler), Let's Encrypt, `APP_DEBUG=false`, `optimize` + `filament:optimize`, zero-downtime deploy, off-site backups, restore drill, Sentry, uptime monitoring | Restore drill documented and passed |

---

## 11. Testing Strategy
| Layer | Scope |
|-------|-------|
| Unit | Money, Allocator, Rounding, YearMonth, LateFeeCalculator, RateResolver, PaidThroughCalculator |
| Feature | Every Action and policy; Filament actions via Livewire test helpers (`callAction`, hidden/visible, confirmation) |
| Invariant | 1,000-seed randomized simulation of §6.7 |
| Concurrency | Double approval, voucher numbering, parallel generation |
| Snapshot | Historical reports unchanged after a rate change |
| Arch | No float/round in domain, final classes, no direct financial writes from UI |

Targets: ≥90% line coverage in `app/Domain` and `app/Support/Money`; every P5.S3 scenario passing.

## 12. Definition of Done (every step)
1. Acceptance criteria are proven by tests; CI (test, Larastan 8, Pint) is green.
2. No logic in UI; Actions are transactional and lock what they mutate.
3. All strings exist in bn and en; Bangla digits on display.
4. Every action has an icon, tooltip, color, confirmation tier, notification and policy.
5. Activity log verified; filter columns indexed; the spec is updated when a rule changes.

## 13. Caveats and Decisions to Confirm
- **Legal percentages:** BR-20 follows s.34 of the Cooperative Societies Act 2001 as published on bdlaws.minlaw.gov.bd.\[2\] The Rules (2004, amended 2020)\[44\] and the somiti's own bylaws may add requirements. Keep the values configurable and get the auditor's sign-off before the first year-end.
- **"Share" meaning:** this spec treats ৳500 → ৳600 as the monthly deposit per share. If there is also a one-time share capital (3101), add `share_capital_per_share_poisha`, charged once like the registration fee.
- **Advance policy:** ratify the default by committee resolution. A later switch only affects payments approved after it.
- **Income recognition:** settlement-based by default. Switch to accrual only on the auditor's request, and before go-live.
- **Confirmation fatigue:** if staff complain, lower T2 to T1 for low-risk edits, but never lower T3.
- **Library drift:** Filament and brick/money move fast. The wrappers (`Money`, `ConfirmsWithTier`, `NavGroup`) isolate the app; pin versions and read the upgrade guides before bumping.

## Sources

1. [Laravel 13 Released: PHP 8.3, Attributes, Laravel AI, and a Smoother Upgrade Path](https://laravel-news.com/laravel-13-released)
2. [সমবায় সমিতি আইন, ২০০১ | সমবায় সমিতিসমূহের সম্পত্তি এবং তহবিলসমূহ](http://bdlaws.minlaw.gov.bd/act-876/chapter-details-1130.html)
3. [Releases · bezhanSalleh/filament-language-switch](https://github.com/bezhanSalleh/filament-language-switch/releases)
4. [bezhansalleh/filament-language-switch - Packagist.org](https://packagist.org/packages/bezhansalleh/filament-language-switch)
5. [How products and prices work](https://docs.stripe.com/products-prices/how-products-and-prices-work)
6. [how products and prices work](https://docs.stripe.com/docs/products-prices/how-products-and-prices-work)
7. [GitHub - brick/money: A money and currency library for PHP · GitHub](https://github.com/brick/money)
8. [A PHP Library to Handle Calculations on Monies of Any Size](https://medium.com/@mayurnaliyapara07/a-php-library-to-handle-calculations-on-monies-of-any-size-1f9f6b083a44)
9. [money/CHANGELOG.md at main · brick/money](https://github.com/brick/money/blob/main/CHANGELOG.md)
10. [Money - ISA](http://thierryroussel.free.fr/java/books/martinfowler/www.martinfowler.com/isa/money.html)
11. [Foemmel's Conundrum](https://en-academic.com/dic.nsf/enwiki/10824067/)
12. [How I built a billing system with a real double-entry ledger in Node + PostgreSQL - DEV Community](https://dev.to/lapistry_dev/how-i-built-a-billing-system-with-a-real-double-entry-ledger-in-node-postgresql-pm9)
13. [ERP General Ledger: Designing a Double-Entry Core in PostgreSQL](https://www.matthewswong.com/en/blog/erp-general-ledger-double-entry-design/)
14. [PostgreSQL: Documentation: 18: 5.5. Constraints](https://www.postgresql.org/docs/current/ddl-constraints.html)
15. [GitHub - codemarquis/accounting-ledger-with-postgres-and-python · GitHub](https://github.com/codemarquis/accounting-ledger-with-postgres-and-python)
16. [Double-Entry Accounting in a Relational Database: Key Tables and Constraints · Faysal Ahmed](https://faysalahmed.space/blog/double-entry-accounting-db-model-2026.md/)
17. [Custom -\>requiresConfirmation message? - Filament](https://www.answeroverflow.com/m/1168937762309808138)
18. [github.com](https://github.com/filamentphp/filament/issues/12877)
19. <https://filamentphp.com/docs/5.x/actions/modals>
20. <https://filamentphp.com/docs/5.x/panel-configuration>
21. [Overview - Filament](https://filamentphp.com/docs/5.x/notifications/overview)
22. [Notifications Overview - Filament](https://mintlify.wiki/filamentphp/filament/notifications/overview)
23. [Database notifications - Filament](https://filamentphp.com/docs/5.x/notifications/database-notifications)
24. [Database Notifications - Filament HUB](https://filament-hub.com/feature/4.x/database-notifications)
25. [Overview - Filament](https://filamentphp.com/docs/5.x/actions/overview)
26. [Actions - Filament](https://filamentphp.com/docs/3.x/tables/actions)
27. [Action iconButton: color() works if -\>url() is specified. If -\>action() the icon is always white. · filamentphp/filament · Discussion #14301](https://github.com/filamentphp/filament/discussions/14301)
28. [Page action button icon center · filamentphp/filament · Discussion #3621](https://github.com/filamentphp/filament/discussions/3621)
29. [Colors - Filament](https://filamentphp.com/docs/5.x/styling/colors)
30. <https://filamentphp.com/docs/5.x/navigation/overview>
31. [Navigate](https://livewire.laravel.com/docs/4.x/navigate)
32. [wire:transition - Livewire - Laravel](https://livewire.laravel.com/docs/4.x/wire-transition)
33. [Overview - Filament](https://filamentphp.com/docs/5.x/tables/overview)
34. [How to disable Per page -\> All - Filament](https://www.answeroverflow.com/m/1275747974143803444)
35. [Overview - Filament](https://filamentphp.com/docs/5.x/tables/columns/overview)
36. [Overview - Tables](https://filamentphp.com/docs/4.x/tables/columns/overview)
37. [Overview - Tables](https://filamentphp.com/docs/5.x/tables/filters/overview)
38. [Build Custom Table Filters in Filament v4 - Steven Richardson](https://richdynamix.com/articles/filament-v4-custom-table-filters)
39. [Advanced - Table Builder - Filament](https://www.laravel-filament.cn/docs/en/3.x/tables/advanced)
40. [Overview - Filament](https://mintlify.wiki/filamentphp/filament/tables/overview)
41. [DB Table State - Filament](https://filamentphp.com/plugins/kisame76-db-table-state)
42. [Laravel 12 Docs MCP Server](https://glama.ai/mcp/servers/@brianirish/laravel-docs-mcp/blob/8e7603711cbe3f41277104357bc6e86e18295942/docs/packages/filament/tables-columns-getting-started.md)
43. [Empty state - Tables - Filament](https://filamentphp.com/docs/4.x/tables/empty-state)
44. [সমবায়-সমিতি-আইন-বিধিসমূহ-(সংশোধিতসহ)](<https://coop.gov.bd/site/view/law/%E0%A6%B8%E0%A6%AE%E0%A6%AC%E0%A6%BE%E0%A7%9F-%E0%A6%B8%E0%A6%AE%E0%A6%BF%E0%A6%A4%E0%A6%BF-%E0%A6%86%E0%A6%87%E0%A6%A8-%E0%A6%AC%E0%A6%BF%E0%A6%A7%E0%A6%BF%E0%A6%B8%E0%A6%AE%E0%A7%82%E0%A6%B9-(%E0%A6%B8%E0%A6%82%E0%A6%B6%E0%A7%8B%E0%A6%A7%E0%A6%BF%E0%A6%A4%E0%A6%B8%E0%A6%B9)>)
