# Clear Member Balances, Fair Late-Joiner Dues and Trustworthy Receipts

*   **Author:** Rabbi
*   **Status:** Built on `feature/member-balances-back-dues` (2026-11-23), except the admin "this month" strip, the dues-page default filter and the collect-page layout. A refused earlier dues start replaces the "force to the share start" rule when back-dues are switched off. See the status table in `docs/audits/financial-ux-audit.md`.
*   **Date:** 2026-11-22

--------------------------------------------------------------------------------

## Overview

Somiti Manager already calculates money correctly. The audit of 2026-11-22 (`docs/audits/financial-ux-audit.md`) re-ran 14 real-life scenarios by hand and against the code and found no calculation error. What it found instead is that:

*   one society rule cannot be expressed at all: a member who joins late cannot be billed for the months before they joined;
*   some figures shown to members change after the fact or are defined differently on different screens;
*   the screens bury the three numbers a member actually needs.

This design fixes those in one piece of work, in five stages:

1.  **Proof first.** Write the 14 scenarios and new nightly consistency checks as automated tests before changing behaviour.
2.  **Late-joiner back-dues.** A late joiner pays every month from the society's start, plus a once-only registration fee, plus a percentage "entry premium" so that existing members are not treated unfairly. Back months never attract late fines and never earn dividend.
3.  **One member position.** One place in the code works out what a member owes, has paid, holds as advance and is "paid through". Every screen, the mobile API, receipts and SMS read from it.
4.  **Frozen receipts and no stranded advance.** A receipt prints the figures as they were at approval, and advance is used up automatically after any reversal.
5.  **Screens.** A phone-first member home in Bangla, and a collection screen that shows what is owed before the cashier types an amount.

Who it is for: members (mostly on phones, many with little experience of apps or accounting), cashiers who collect money, and accountants and the president who approve and reconcile it.

## The Problem

**1. Late joiners cannot be charged what the society requires.**
Monthly dues are created only for months in which a member holds shares (`app/Domain/Contributions/Services/MonthlyDueBuilder.php:28`). A new member's shares can only start after the latest month already billed (`app/Domain/Members/Services/ShareChanger.php:196`). So someone who joins in May can never be billed for January–April. Backdating shares is not a workaround: dividends are shared by "share-months", so backdated shares would pay the newcomer profit for months they were not a member.

The society's rule is that a late joiner pays every month from the start. On top of that, someone who joins after a year and pays ৳5,000 at once should not end up equal to someone whose ৳5,000 has been working for the society all year. Today the app has no way to charge either.

**2. Receipts change after they are issued.**
The "paid through" line on a receipt is calculated when the receipt is printed, not when the payment was approved (`app/Reports/ReceiptDocument.php:37`). A receipt reprinted next month shows different text. Receipts also do not show how much was owed before the payment and how much is still owed after it.

**3. The same idea means different things on different screens.**
*   "Paid through" counts any unpaid item, so one unpaid ৳20 late fine can make a fully paid member look months behind.
*   "Current outstanding" on the member home includes months that are not due yet.
*   The collection preview and the receipt work out "months covered by advance" in two different ways.
*   The preview settles any open due, while automatic advance only settles dues that are already due.

**4. Money can sit unused while the member still owes.**
After a payment or an advance refund is reversed, the member can show both an unpaid due and an advance balance until the next monthly run. That is confusing, and wrong in spirit.

**5. Screens hide what matters.**
*   **Member home:** eight equal cards, about half of them usually showing ৳0.00.
*   **Monthly deposit card:** it shows ৳1,800 while the member actually pays ৳1,860, because the service charge is left out.
*   **Dues page:** it opens empty when nothing is owed, so members cannot see which months are paid.
*   **Collection screen:** what the member owes sits below the form, and the save button is far from it.
*   **Duplicate cash entries:** two identical entries on the same day are both approved after only a warning, and the extra money silently becomes advance.

## Goals

1.  **Late joiners are billed by the society's rule, with a full breakdown before anyone confirms.** For every month from the dues start month up to the latest billed month, the member is charged:
    *   that month's deposit and service charge, at that month's own rate;
    *   one registration fee per share;
    *   an entry premium equal to a configurable percentage of the back-months total.

    Example: 5 back months at ৳1,000 deposit and ৳20 service charge, 1 share, ৳200 registration fee, 10% premium.
    *   Back months: 5 × (৳1,000 + ৳20) = ৳5,100
    *   Registration fee: ৳200
    *   Entry premium: 10% of ৳5,000 deposits = ৳500
    *   **Total to pay: ৳5,800**

    The premium is a percentage of the back deposits only (decided while drafting), so the example premium is ৳500 and the total is **৳5,800**.
2.  **Back months are never fined and never earn dividend.** The nightly late-fine run skips them. Dividend weighting is still based on months of actual share ownership.
3.  **The entry premium is society income.** It is posted to its own income account and never to the member's savings.
4.  **One definition of each member figure.** "Owe now", "coming up", "you have paid", "advance" and "paid through" each have one written definition and one implementation. The member portal, mobile API, collection preview, receipt and SMS all use it.
5.  **Receipts never change.** At approval the app stores what the member owed before, what was received, what is still owed after, what advance is held and the paid-through month. Every print of that receipt shows exactly those figures.
6.  **No stranded advance.** After any reversal, an advance balance is applied to open dues in the same database transaction. A nightly check reports any member who has both.
7.  **Duplicates need a reason.** A cash payment that matches an existing one (same member, amount and day) cannot be saved without a written reason, and the approver sees it flagged.
8.  **Members find what they owe within 5 seconds of the home screen loading.** One summary block (owe now / paid / advance, in Bangla by default), a 12-month strip showing each month's state, and every other figure one tap away.
9.  **A cashier records a payment in three actions after finding the member:** type or tap the amount, press Record, confirm. What the member owes is visible before typing.
10. **Every figure is proven nightly.** New consistency checks join the existing nightly run and finish with zero findings on the demo and on production data:
    *   per-member savings and advance match the ledger;
    *   every active member has every month billed from their dues start month;
    *   no member has stranded advance;
    *   stored receipt figures recompute to the same values;
    *   possible duplicate cash entries are listed.
11. **All 14 audit scenarios are permanent automated tests** with hand-calculated expected amounts.

## Non-Goals

*   **A new member portal.** The Filament member panel at `/portal` is the member web UI and stays. The Livewire portal described in `SOMITI_SPEC.md` was never built and will not be. The spec text is corrected instead.
*   **Changing how payments are allocated.** Oldest month first (BR-13) stays. Cashiers still do not pick months.
*   **Editing payments.** Corrections stay as cancel or reject (while pending) or reverse and re-record (once approved).
*   **Waiving deposits or service charges.** Only late fines can be waived, as today.
*   **A profit-based entry premium** (worked out from the society's actual earnings). That is a possible later upgrade; this design uses a fixed percentage.
*   **Booking receivables in the general ledger.** The app keeps recording dues when paid. Unpaid dues stay a member sub-ledger, proven by the new checks.
*   **Retroactive rate corrections, or billing members who were reactivated** after being inactive (known gaps 5 and 14).
*   **Changing the production deployment, or the memory issues** in `docs/designs/production-oom-brief.md`.

## Open Questions

*   **What is the starting entry premium percentage?** It is 0% until the committee approves a value in Financial settings. This does not block building.

Resolved while drafting (kept for the record):

*   **"Society's start month"** means the go-live month. A dues start month cannot be earlier than go-live.
*   **The entry premium base** is back-month deposits only.
*   **The dues start month is fixed** once the member is created. A wrong one is undone by "Cancel back-dues" (a second person is required, and nothing may have been paid yet).
*   **Members get an SMS when a payment is recorded** as well as when it is approved.
*   **The date basis for "collected this month"** is out of scope (known gap 13).

## Critical User Journeys (CUJs)

### CUJ 1: A member joins five months late

*   **Given**:
    *   go-live and dues start is January;
    *   January–May are billed;
    *   the rate is ৳1,000 deposit + ৳20 service charge per share;
    *   registration fee is ৳200 per share;
    *   entry premium is 10%.
*   **When**: the secretary adds Rahim in May with 1 share and dues start January.
*   **Then**: before confirming, the screen shows:
    *   January–May, ৳1,020 each, ৳5,100 in total;
    *   registration fee ৳200;
    *   entry premium ৳500 (10% of ৳5,000 deposits);
    *   the grand total.

    After confirming, Rahim's dues list contains exactly those items. No late fines appear the next morning. His dividend for the year counts share-months from May only.

### CUJ 2: The late joiner pays part

*   **Given**: Rahim from CUJ 1, owing the full total.
*   **When**: the cashier records ৳3,000 and an accountant approves it.
*   **Then**:
    *   the money settles the oldest items first;
    *   the receipt lists what each taka paid for, what was owed before, ৳3,000 received, what is still owed, and "paid through";
    *   Rahim's home screen shows the same "still owed" figure;
    *   reprinting the receipt in July shows identical text.

### CUJ 3: A member checks their position on a phone

*   **Given**: Salma has paid through October and November is billed but not yet paid.
*   **When**: she opens `/portal` on her phone.
*   **Then**:
    *   the first block shows, in Bangla: owe now ৳1,860, total paid, advance ৳0;
    *   below it, a month strip shows July–October as paid and November as unpaid;
    *   the monthly amount shown is ৳1,860 (deposit + service charge), not ৳1,800.

### CUJ 4: A cashier collects at the door

*   **Given**: Jamal owes August–October (৳1,920).
*   **When**: the cashier finds Jamal and taps "up to September".
*   **Then**:
    *   ৳1,280 is filled in;
    *   the screen says it is expected to settle August and September, with ৳640 still owed, final on approval;
    *   the cashier presses Record and confirms;
    *   Jamal gets a "received, waiting for approval" message .

### CUJ 5: A wrong payment is reversed

*   **Given**: a ৳1,500 payment settled July and left ৳1,000 as advance, and August is now billed.
*   **When**: the president reverses the payment.
*   **Then**:
    *   July reopens;
    *   any advance still held is applied to the oldest open dues in the same step;
    *   the member never shows "owes" and "advance" at the same time;
    *   the nightly checks report zero findings.

### CUJ 6: The same cash is entered twice

*   **Given**: ৳500 cash for Karim was recorded today.
*   **When**: a second cashier records ৳500 cash for Karim today.
*   **Then**: saving is refused until a reason is typed, and the approver sees the entry marked as a possible duplicate with the reason.

## Technical Plan (Architecture)

### Decisions recorded for this plan

| Decision | Choice | Decided by |
|---|---|---|
| How back-dues are marked | A new `origin` column on `dues` (`regular` / `opening` / `back_dues`) replaces the `opening` boolean. Back months stay ordinary `deposit` and `service_charge` dues; the premium is a new due type `entry_premium` | Author |
| Where receipt figures are frozen | New columns on `payments`, filled in at approval | Author |
| Meaning of "paid through" | Latest month M where every deposit and service-charge due for months up to and including M is settled, waived or cancelled. Late fines, registration fee and entry premium never move it; they are shown beside it as "other amounts still owed" | Author |
| "Owe now" vs "coming up" | Owe now = open dues whose `due_date` ≤ today. Coming up = open dues whose `due_date` > today | Proposed; author to confirm in review |
| "You have paid" | Sum of approved payments, not counting reversed ones | Proposed; author to confirm in review |
| Which dues a payment or advance may settle | Any open, already-billed due, oldest first. One rule for the collection preview, approval and automatic advance (today `ApplyAdvance` alone skips dues not yet due) | Proposed; author to confirm in review |

### 1. Data model changes

```text
members
  + dues_start_month  date NOT NULL, CHECK first day of month
                      (backfill: imported members → go-live month;
                       in-app members → earliest share lot effective_from)

dues
  + origin  varchar(12) NOT NULL DEFAULT 'regular'
            CHECK origin IN ('regular','opening','back_dues')
            (backfill: opening = true → 'opening'; then drop the boolean)
  ~ type CHECK adds 'entry_premium'

payments
  + outstanding_before_poisha  bigint NULL   ┐ filled in at approval;
  + outstanding_after_poisha   bigint NULL   │ CHECK: when status is approved or
  + other_owed_after_poisha    bigint NULL   │ reversed AND approved_at is after this
  + advance_after_poisha       bigint NULL   │ release, all five are NOT NULL
  + paid_through_after         date   NULL   ┘ (older payments stay NULL)
  + duplicate_reason           text   NULL   (required by the Action when a
                                              same-day same-amount cash
                                              payment exists)

accounts (chart)
  + 4141 Entry Premium Income / প্রবেশ প্রিমিয়াম আয়   (income, no member tag)

financial settings (existing versioned JSON, maker-checker)
  + entry_premium_bps  (0 until the committee approves a value)
```

### 2. Late-joiner back-dues

Both ways a member is created already end in the `CreateMember` Action: the staff "New member" form, and approval of an online registration (`DecideRegistration::activate`). Back-dues are created inside that same database transaction, so a member either exists with all their back-dues or does not exist at all.

```text
Staff form / registration approval
        │  shares, share start month (unchanged), dues start month (new)
        ▼
BackDuesPlanner.plan(member, shares, duesStart)      ← same code for the preview
        │  months = duesStart … latest generated month
        │  for each month: that month's approved rate plan (refuse if none)
        │    deposit        = share_unit × shares
        │    service charge = service_charge_per_share × shares
        │  premium = entry_premium_bps × Σ deposits, rounded half-up once
        ▼
CreateMember (one transaction)
   member → share lot (existing rule: starts after latest generated month)
          → registration fee once per share (existing RegistrationFees)
          → back-dues rows: origin = back_dues, share_lot_id = joining lot,
            due_date = joining date, snapshot = that month's plan
          → entry_premium due (month = joining month) if premium > 0
          → activity log "back_dues_created" with the breakdown
```

The rules this enforces:

*   **Allowed dues start months.** The dues start month must be no earlier than the go-live month and no later than the share lot's start month. If it equals the share start month, there are no back-dues, which is the current behaviour.
*   **No double billing.** The joining lot is never "active" in the back months, so the monthly due run cannot bill those months a second time. The `dues_natural_key` unique constraint is the last line of defence.
*   **No fines.** `ApplyLateFees` already skips opening dues. It changes to skip any due whose `origin` is not `regular`, which covers both opening and back-dues.
*   **No dividend for back months.** Dividends keep reading share lots only (`PrepareYearEnd` / `ShareMonths`), and back-dues never create or move a lot.
*   **Where the money goes.** When back-dues are paid, the deposits go to member savings (2101), the service charges to 4111 and the premium to 4141. `DuePosting` gains one mapping line.
*   **Allocation order inside a month:** late fine → service charge → registration fee → entry premium → deposit. This extends today's order. Because payments settle the oldest month first, older back months are always paid before the premium in the joining month.

The preview the secretary sees before confirming comes from `BackDuesPlanner.plan`, the same code that creates the rows. The secretary sees one row per back month, then the registration fee, the premium, and the grand total.

### 3. One member position

A new read service, **`MemberPosition`**, replaces `MemberSummary` and absorbs `PaidThroughCalculator` and the advance-coverage part of `PaymentPreviewer`. Every consumer reads from it:

```text
                     dues + payment_allocations + payments + advance_ledger_entries
                                              │
                                              ▼
                                       MemberPosition
      ┌──────────┬──────────────┬─────────────┼────────────┬────────────┬──────────────┐
      ▼          ▼              ▼             ▼            ▼            ▼              ▼
  portal home  mobile API   collect page  ApprovePayment  receipt    payment SMS   member dues
  (widget)     dashboard    preview       (frozen figs)   PDF        template      month strip
```

What it returns for a member, as of a date:

*   `oweNow`, `comingUp`, and `otherOwed` (the non-monthly items included in `oweNow`);
*   `paidTotal` and `advance`;
*   `savings`, the member's 2101 balance as today;
*   `paidThrough`, under the new definition;
*   `monthlyAmount`, which is deposit plus service charge for the member's shares at the current rate;
*   `advanceCoversMonths`, an estimate: whole months of `monthlyAmount`, counted from the month after `paidThrough`;
*   `months`, the last 12 months, each with one state.

The possible month states:

| State | Meaning |
|---|---|
| `paid` | Every monthly charge for the month is settled |
| `partly_paid` | Some of the month's charges are paid |
| `unpaid` | Nothing paid and the due date has passed |
| `not_due_yet` | Billed, but the due date has not passed |
| `waived` | Waived (only late fines can be waived today; kept for completeness) |
| `cancelled` | The month's charges were cancelled |
| `before_start` | Before the member's dues start month |
| `not_billed` | The month has not been generated yet |

A payment that is still waiting for approval is shown as a separate "waiting for approval ৳X" line. It is never shown as a month state, because it has not been allocated yet.

### 4. Frozen receipts

`ApprovePayment` already holds row locks on the member and their dues. Inside that transaction it now does the following:

1.  Reads `MemberPosition` before allocating, and stores `oweNow` in `outstanding_before`.
2.  Allocates and settles exactly as today.
3.  Reads `MemberPosition` again and stores `outstanding_after`, `other_owed_after`, `advance_after` and `paid_through_after`.

`ReceiptDocument` prints only these stored figures, under the heading "এই রসিদের সময়ে" ("as at this receipt"). Payments approved before this release have no stored figures. Their receipts keep the current live calculation, labelled "as at print date", so nobody mistakes them for frozen figures.

The payment SMS uses the same stored figures. Keep it short, because Bangla SMS are charged per 70 characters: "৳X received, paid through <month>, still owed ৳Y" plus the portal link.

### 5. No stranded advance

At the end of their transactions, `ReversePayment` and `ReverseAdvanceRefund` call `ApplyAdvance` for that one member. The member row is already locked, so the lock order (member → dues → advance → voucher) holds. `ApplyAdvance` changes to settle any open, already-billed due, not only dues that are already due. That makes its rule identical to the preview and approval rule.

### 6. Duplicate cash entries

`RecordPayment` runs the existing `SimilarPayments` check inside its locked transaction. If a cash payment with the same member, amount and date already exists (pending or approved) and no `duplicate_reason` is given, it refuses with `payments.errors.duplicate_needs_reason`. The collection screen shows a reason box when the warning appears. The pending-approvals list and the approval dialog show "possible duplicate" together with the reason.

A matching bKash or Nagad payment is already refused by its unique TrxID, so this rule applies to cash and bank only.

### 7. Collection preview made honest

`PaymentPreviewer.preview` gains two changes:

*   It subtracts the member's other **pending** payments first. Those will be allocated before this one, and the screen says "N pending payments counted".
*   The result is labelled "expected; final on approval".

The approval dialog shows the actual allocation the accountant is about to post, so if anything changed in between, the person approving sees it.

### 8. New nightly checks

These checks join `somiti:integrity:check` (02:00), and any finding raises the existing red banner and alert:

| Check | Finding when |
|---|---|
| `StrandedAdvance` | A member has advance > 0 and an open, already-billed due |
| `DuesCompleteness` | An active member is missing a deposit due for a generated month between their dues start month and today (and before any exit month) |
| `ReceiptFigures` | For a payment with frozen figures: `outstanding_before − (amount − advance part) ≠ outstanding_after`, or the allocations plus the advance part ≠ amount |

Possible duplicate cash entries are **not** an integrity failure, because a real second payment is legitimate. They show as a filter on the Payments list instead.

### 9. Screens

**Member home** (`/portal`, phone first, Bangla by default for members). One summary card contains:

*   the big "আপনার বকেয়া" (what you owe), coloured, with a tick when it is ৳0;
*   "অক্টোবর ২০২৬ পর্যন্ত পরিশোধিত" (paid through October 2026) and, if any, "অন্যান্য বকেয়া ৳X" (other amounts still owed);
*   a small row with total paid, advance and savings;
*   the 12-month strip, where each month has an icon and text as well as a colour;
*   one line with the true monthly amount (deposit plus service charge);
*   a "pay by bKash/Nagad" button.

Everything else goes behind "বিস্তারিত" (details). Amounts drop ".00" when there are no poisha.

**Member dues page:**

*   It lists all months by default, newest first.
*   Each month is one row with a breakdown that expands, a "partly paid" label and a "from joining" label for back-dues.

**Member receipts list** gains a "for months" column, for example "আগ–সেপ ২০২৬" (Aug–Sep 2026).

**Collection screen:**

*   Order: member search, then the member's position with month chips you can tap, then amount / method / date, then a "more" section for proof and notes. The Record button sits at the bottom of the form.
*   Each month is shown as one row: its total, with an expandable breakdown.

**New member form and registration approval:**

*   A "dues start month" field, defaulting to the go-live month.
*   A live breakdown table and grand total from `BackDuesPlanner`, shown inside the T2 confirmation.

**Admin dashboard** gains a "this month" strip:

*   billed;
*   collected, with the percentage of billed;
*   still owed;
*   advance held;
*   waiting for approval.

Each tile says which date it counts by.

**Status badges:** waived and cancelled get different colours and icons.

### 10. Rollout and safety

*   **Migrations only add.** The `opening` → `origin` change copies the data before dropping the boolean, inside one migration, and the integrity run must be clean before and after. Migrations go forward only, per the project rules.
*   **Back-dues are off until the committee sets them up.** With the premium at 0%, back-dues are still created, at no extra charge. The "dues start month" field defaults to the share start month (no back-dues) until a `back_dues_enabled` financial setting is approved. Production behaviour therefore does not change on deploy.
*   **The stricter rules have no switch.** The frozen receipts, the advance fix and the duplicate-reason rule apply from deploy.
*   **Production is checked before rollout.** Run `somiti:integrity:check` on production data before deploying, so that old findings are not blamed on this change.

## Alternatives Considered

### Alternative A: Backdate the late joiner's shares

*   **Pros**:
    *   No new columns.
    *   The monthly due run would bill the earlier months by itself.
*   **Cons**:
    *   The app refuses it: `ShareChanger.php:196` blocks share changes in already-billed months.
    *   Dividends are weighted by share-months, so backdating would pay the newcomer profit for months they were not a member, which is the exact injustice the society wants to avoid.
    *   The share register would record ownership that never happened.
*   **Reason for Rejection**: Rejected by author: it breaks the fairness rule that motivated the feature and falsifies the share register.

### Alternative B: Re-run the monthly due generation for past months

*   **Pros**:
    *   It reuses `GenerateMonthlyDues` unchanged.
*   **Cons**:
    *   It only bills members with shares active in that month, so it is the same problem as Alternative A.
    *   It picks members by their *current* status.
    *   It refuses months before go-live.
    *   There is no preview at sign-up.
*   **Reason for Rejection**: Rejected by author: it cannot bill a late joiner without backdating shares, and the secretary would get no total before confirming.

### Alternative C: Let the cashier choose which months a payment covers

*   **Pros**:
    *   It matches how some collectors think ("this is March's money").
*   **Cons**:
    *   Members could skip a month that carries a late fine.
    *   "Paid through" would stop meaning "every month before this is paid".
    *   Two cashiers could record the same money against different months.
    *   The oldest-first rule (BR-13) and its tests would have to be rewritten.
*   **Reason for Rejection**: Rejected by author: the collection screen's "clear up to here" month buttons give a month-based way to pick an amount, while the allocation stays oldest first.

### Alternative D: New due types (`back_deposit`, `back_service_charge`) instead of an `origin` column

*   **Pros**:
    *   Each kind of charge is explicit wherever dues are read.
    *   Posting rules are written per type.
*   **Cons**:
    *   More than ten places ask "is this a deposit?":
        *   member summary;
        *   paid-through;
        *   defaulters;
        *   statements;
        *   integrity checks;
        *   allocation order;
        *   the mobile API.
    *   Missing any one of them silently drops back-deposits from savings totals or from "paid through", with no error.
*   **Reason for Rejection**: Rejected by author: the risk of a silent money-display error is higher than the cost of one column.

### Alternative E: Reuse the existing `opening` flag for back-dues

*   **Pros**:
    *   It is the smallest change, because the late-fine run already skips opening dues.
*   **Cons**:
    *   "Arrears brought in from the old paper books at go-live" and "a late joiner's back months" become indistinguishable in reports and audits.
*   **Reason for Rejection**: Rejected by author: the two have different origins and must stay separable for audit.

### Alternative F: Store frozen receipt figures in a separate `receipts` table

*   **Pros**:
    *   Receipt numbers independent of voucher numbers.
    *   Reversals could get their own printed slip.
*   **Cons**:
    *   A second record that must be kept in step with each payment.
    *   The database cannot as easily enforce that every approved payment has its figures.
*   **Reason for Rejection**: Rejected by author: there is one receipt per payment, the voucher number already serves as the receipt number, and columns on `payments` can be enforced by a database check.

### Alternative G: "Paid through" counts every unpaid item, including fines, registration and the entry premium

*   **Pros**:
    *   It is stricter, and it is today's behaviour.
*   **Cons**:
    *   One unpaid ৳20 fine from March makes a member who paid every month through November look "paid through February".
    *   A late joiner's unpaid entry premium would do the same.
*   **Reason for Rejection**: Rejected by author: members read "paid through" as "which monthly chada is paid". Other amounts owed are shown next to it, so nothing is hidden.

### Alternative H: Entry premium based on actual profit earned (a profit catch-up)

*   **Pros**:
    *   The fairest measure: the newcomer pays in proportion to what the society actually earned before they joined.
*   **Cons**:
    *   It needs a full year of results and a written formula covering distributed versus retained profit.
    *   It is harder to explain to members.
*   **Reason for Rejection**: Rejected by author for now in favour of a fixed percentage set in Financial settings; listed as a later upgrade.

### Alternative I: Book unpaid dues in the general ledger (account 1201 Dues Receivable)

*   **Pros**:
    *   "What you owe" would be a ledger balance, not only a sub-ledger figure.
*   **Cons**:
    *   It changes the society's accounting policy from recording dues when paid to recording them when billed.
    *   It affects closed-period handling, every report, and year-end.
*   **Reason for Rejection**: Rejected by author: the nightly checks give the same assurance without changing accounting policy.

### Alternative J: Build the Livewire member portal described in the spec

*   **Pros**:
    *   Full control of the phone layout.
*   **Cons**:
    *   It would be a second member web UI next to the working Filament panel at `/portal`, with a second place where numbers could disagree.
*   **Reason for Rejection**: Rejected by author: improve the existing panel and correct the spec instead.

--------------------------------------------------------------------------------

## Non-Functional Requirements

*   **Money safety.**
    *   Every new write runs inside the existing financial Actions, using `DB::transaction(..., attempts: 3)` and the fixed lock order member → dues → advance ledger → voucher sequence.
    *   No money is stored as float.
    *   The premium is rounded once, half-up, the same way as late fees (spec §6.2).
*   **Rollout.**
    *   All migrations go forward only.
    *   Back-dues stay off until the `back_dues_enabled` financial setting is approved, using the same propose/approve flow as other financial settings.
    *   The integrity run must be clean on production before deploy and the morning after.
*   **Rollback.** No rollback migration is written, per the project rules. If a problem appears:
    *   switch `back_dues_enabled` off;
    *   cancel any wrong back-dues with the new T3 action;
    *   deploy a fix forward.
*   **Performance.**
    *   `MemberPosition` reads one member's dues and allocations, which is a few hundred rows at most.
    *   The new nightly checks are single SQL aggregates over `dues`, `payments` and `advance_ledger_entries`.
    *   None of this adds per-request work to staff list pages.
*   **SMS cost.** The new "received, waiting for approval" SMS adds one SMS per payment. Templates are kept under 70 Bangla characters plus the link.
*   **Privacy.** No new personal data is collected. `duplicate_reason` is staff-only.

--------------------------------------------------------------------------------

## Detailed Implementation Plan

Decided while drafting this section; change any of these in review:

*   **The entry premium is a percentage of back-month deposits only.** Service charges and the registration fee are excluded. Example: 5 × ৳1,000 at 10% = ৳500.
*   **The dues start month cannot be edited after the member is created.** A mistake is fixed with **Cancel back-dues**: a T3 action, done by someone other than the creator, allowed only while none of the back-dues or the premium has any money applied.
*   **Members get a short SMS when a payment is recorded** ("৳X received, waiting for approval"), as well as the existing one on approval.
*   **Which date "collected this month" counts by is out of scope** (known gap 13). The voucher date already equals the received date.

Steps are listed in build order. Each step ends with `composer test`, `composer analyse` and `composer format:check` passing (`CLAUDE.md`).

### Stage 0: Tests first

#### 1. `tests/Feature/Contributions/AuditScenariosTest.php` (Create)

*   **Type / Scope**: Pest feature test.
*   **Proposed Changes**:
    *   Promote the audit scenario file (scratchpad copy `AuditScenariosTest.php`), replacing every `dump()` with assertions.
    *   Plan: 1 share, ৳500, no service charge, no registration fee.
    *   Hand-calculated cases:
        *   S1 on-time;
        *   S3/S4 partial (৳1,500 of ৳2,500 → Jul–Sep settled, ৳1,000 owed, paid through Sep);
        *   S5 advance;
        *   S6 under-payment;
        *   S9 reversal;
        *   S10 duplicate cash (refused without a reason, accepted with one);
        *   S10b duplicate TrxID;
        *   S13 rate change;
        *   S14 advance-refund reversal (dues cleared, advance 0).
    *   Every case ends with `assertBooksTieOut()`.
    *   The S10 and S14 expectations describe the *new* behaviour, so they fail until Stage 3 and Stage 4.

#### 2. `tests/Feature/Contributions/BackDuesTest.php` (Create)

*   **Type / Scope**: Pest feature test.
*   **Proposed Changes**:
    *   **CUJ 1 setup:** go-live January, Jan–May generated, ৳1,000 deposit, ৳20 service charge, ৳200 registration fee, 10% premium.
    *   **Expected dues:**
        *   Jan–May deposits of ৳1,000 and service charges of ৳20, all with `origin = back_dues`;
        *   registration fee ৳200 once;
        *   premium ৳500 (`entry_premium`, posted to 4141 when paid).
    *   **No late fines** after running `ApplyLateFees` for 2026-06-15.
    *   **Dividend:** `ShareMonths` for the year starts at the share lot month.
    *   **Rate change during the back months:** ৳500 until March and ৳600 from April gives three months at ৳500 and two at ৳600.
    *   **Refusals:**
        *   a dues start month before go-live (`members.errors.dues_start_before_go_live`);
        *   a dues start month after the share start (`members.errors.dues_start_after_shares`);
        *   a back month with no approved rate plan (`members.errors.no_rate_plan`).
    *   **Setting off:** with `back_dues_enabled` off, the dues start month is forced to the share start month and no back-dues are created.
    *   **Preview equals result:** `BackDuesPlanner::plan()` totals equal the created rows.
    *   **Paying in part:** paying ৳3,000 settles January through the oldest items first; the April and May deposits stay open, and so does the premium.
    *   **Cancel back-dues:**
        *   refused when the same user cancels;
        *   refused once anything is paid;
        *   when it succeeds, the dues become `cancelled` and the activity log records the reason.

#### 3. `tests/Feature/Contributions/MemberPositionTest.php` (Create)

*   **Type / Scope**: Pest feature test.
*   **Proposed Changes**:
    *   **Paid through:** paid every month but owing a ৳20 March fine gives paid through November and other amounts owed ৳20.
    *   **Partly paid:** a partly paid oldest month gives paid through = the month before it.
    *   **Owe now vs coming up:** they split on `due_date`.
    *   **Paid total:** `paidTotal` excludes reversed payments.
    *   **Monthly amount:** `monthlyAmount` = (deposit + service charge) × shares.
    *   **Month states:** each of the 8 month states is produced by a fixture.
    *   **Advance coverage:** ৳2,500 at ৳500 per month covers 5 months.

#### 4. `tests/Feature/Contributions/ReceiptFiguresTest.php` (Create)

*   **Type / Scope**: Pest feature test.
*   **Proposed Changes**:
    *   **Frozen figures:** approving fills all five frozen columns.
    *   **Reprints:** reprinting after two later payments gives identical PDF text for those fields. Assert on the view data, not the PDF bytes.
    *   **Old payments:** a legacy payment (columns null) renders the "as at print date" label.
    *   **Database check:** setting an approved payment's frozen column to null fails.

#### 5. `tests/Feature/Integrity/NewChecksTest.php` (Create)

*   **Type / Scope**: Pest feature test.
*   **Proposed Changes**: each check reports zero findings on a clean scenario and exactly one finding on a fixture that breaks it:
    *   **`StrandedAdvance`:** insert advance and an open due directly.
    *   **`DuesCompleteness`:** delete nothing; create a member whose dues start month predates a generated month without a due.
    *   **`ReceiptFigures`:** tamper with `outstanding_after_poisha` through raw SQL with the guard trigger disabled in the test.

#### 6. `tests/Concurrency/PaymentRecordingTest.php` (Create)

*   **Type / Scope**: Pest concurrency test, using the existing `inParallel()` helper.
*   **Proposed Changes**:
    *   **Concurrent recording:** two cashiers record the same ৳500 cash for one member at the same moment, with no reason. Exactly one is saved; the other gets `payments.errors.duplicate_needs_reason`.
    *   **Concurrent approval:** two different payments for one member are approved concurrently by two accountants. The allocations never exceed the dues, and the advance is correct.

### Stage 1: Schema and settings

#### 7. `database/migrations/2026_11_23_000001_add_dues_origin.php` (Create)

*   **Type / Scope**: PostgreSQL migration.
*   **Proposed Changes**:
    *   Add `dues.origin varchar(12) NOT NULL DEFAULT 'regular'`.
    *   Run `UPDATE dues SET origin = 'opening' WHERE opening`.
    *   Add the CHECK `origin IN ('regular','opening','back_dues')`.
    *   Drop `dues.opening`.
    *   Replace `dues_type_valid` so it also allows `'entry_premium'`.
    *   Add the index `(member_id, origin)`.
    *   The `dues_guard` trigger already allows inserts; confirm it treats `origin` as immutable after insert.

#### 8. `database/migrations/2026_11_23_000002_add_members_dues_start_month.php` (Create)

*   **Type / Scope**: PostgreSQL migration.
*   **Proposed Changes**:
    *   Add a nullable `members.dues_start_month date`.
    *   Backfill imported members (those with any `origin = 'opening'` due or an opening-import row) with the go-live month, and everyone else with `MIN(share_lots.effective_from)`.
    *   Then set it `NOT NULL`, add a first-of-month CHECK, and add a trigger that refuses updates to this column.

#### 9. `database/migrations/2026_11_23_000003_add_payment_receipt_figures.php` (Create)

*   **Type / Scope**: PostgreSQL migration.
*   **Proposed Changes**:
    *   Add five nullable columns to `payments`: `outstanding_before_poisha`, `outstanding_after_poisha`, `other_owed_after_poisha`, `advance_after_poisha` and `paid_through_after`.
    *   Add the nullable `payments.duplicate_reason text`.
    *   Add a CHECK, `NOT VALID` so existing rows are not checked: when `status IN ('approved','reversed') AND approved_at >= <migration timestamp>`, all five are NOT NULL.
    *   Extend the existing payments guard trigger so these five columns cannot change once set.

#### 10. `database/migrations/2026_11_23_000004_add_entry_premium_account.php` (Create)

*   **Type / Scope**: Data migration.
*   **Proposed Changes**: insert account 4141 "Entry Premium Income" / "প্রবেশ প্রিমিয়াম আয়" (income, no member tag), if it is missing, so existing databases get it.

#### 11. `database/seeders/ChartOfAccountsSeeder.php` (Modify)

*   **Type / Scope**: Seeder.
*   **Proposed Changes**: add the 4141 row next to 4131, for fresh installs and tests.

#### 12. `app/Domain/Accounting/AccountCode.php` (Modify)

*   **Proposed Changes**: add `ENTRY_PREMIUM_INCOME = '4141'`.

#### 13. `app/Domain/Contributions/Enums/DueOrigin.php` (Create)

*   **Type / Scope**: Backed enum implementing `HasLabel`, `HasColor` and `HasIcon`.
*   **Proposed Changes**: cases `Regular`, `Opening` and `BackDues`, with labels such as "from joining" and "opening arrears".

#### 14. `app/Domain/Contributions/Enums/DueType.php` (Modify)

*   **Proposed Changes**:
    *   Add `EntryPremium = 'entry_premium'`.
    *   Reorder the cases so `defaultAllocationOrder()` gives late_fee, service_charge, registration, entry_premium, deposit.
    *   Add its colour.

#### 15. `app/Domain/Contributions/Enums/DueStatus.php` (Modify)

*   **Proposed Changes**: give `Waived` its own colour (`info`) and give both `Waived` and `Cancelled` distinct icons, instead of the shared grey.

#### 16. `app/Domain/Contributions/Models/Due.php` (Modify)

*   **Proposed Changes**: cast `origin` to `DueOrigin`.

#### 17. `app/Domain/Members/Models/Member.php` (Modify)

*   **Proposed Changes**: cast `dues_start_month` to `YearMonth`, and refuse any change to it in the model guard.

#### 18. `app/Domain/Contributions/Models/Payment.php` (Modify)

*   **Proposed Changes**:
    *   Cast the five frozen columns: `MoneyCast` for the amounts, `YearMonth` for the month.
    *   Add a `hasFrozenFigures(): bool` helper.

#### 19. `app/Domain/Settings/Data/FinancialSettingsData.php` (Modify)

*   **Proposed Changes**:
    *   Add `bool $backDuesEnabled` (default false) and `Bps $entryPremium` (default 0), in `fromConfig`, `fromStored`, `fromForm` and `toForm`.
    *   Validate the premium as 0–100%.

#### 20. `config/somiti.php` (Modify)

*   **Proposed Changes**: add the defaults `back_dues_enabled => false` and `entry_premium_bps => 0`.

#### 21. The Financial settings Filament page (Modify)

*   **Type / Scope**: Filament page; the file is the one that renders `FinancialSettingsData::toForm()`.
*   **Proposed Changes**: add a toggle and a percent input in a new "Late joiners" section, with help text and a ৳ example.

#### 22. `lang/en/financial_settings.php`, `lang/bn/financial_settings.php` (Modify)

*   **Proposed Changes**: add labels and help text for the two new settings.

### Stage 2: Back-dues

#### 23. `app/Domain/Contributions/Services/BackDuesPlanner.php` (Create)

*   **Type / Scope**: Final service class.
*   **Proposed Changes**: `plan(int $shares, YearMonth $duesStart, YearMonth $shareStart): BackDuesPlan`.
    *   **Months covered:** `duesStart` up to `min(shareStart.previous(), GeneratedMonths::latest())`.
    *   **Price per month:** `RateResolver::for(month)`, which throws `NoRatePlanForMonth` and is mapped to `members.errors.no_rate_plan`. Deposit = share_unit × shares, and service = service_per_share × shares.
    *   **Premium:** `entryPremium` bps × Σ deposits, rounded half-up once.
    *   **When it returns an empty plan:** if `back_dues_enabled` is off, or `duesStart == shareStart`.
    *   **Validation:**
        *   go-live ≤ duesStart ≤ shareStart, otherwise `members.errors.dues_start_before_go_live` or `members.errors.dues_start_after_shares`;
        *   duesStart must be the first of the month.

#### 24. `app/Domain/Contributions/Data/BackDuesPlan.php` (Create)

*   **Type / Scope**: `final readonly class`.
*   **Proposed Changes**:
    *   Holds `list<array{month: YearMonth, ratePlanId: int, deposit: Money, serviceCharge: Money}>`, plus `Money $premium` and `Money $total`.
    *   Provides an `isEmpty()` method.

#### 25. `app/Domain/Contributions/Services/BackDuesWriter.php` (Create)

*   **Type / Scope**: Final service, called only inside `CreateMember`'s transaction.
*   **Proposed Changes**:
    *   Inserts the dues rows from a `BackDuesPlan`: `origin = back_dues`, `share_lot_id` = the joining lot, `adjustment_seq = 0`, `due_date` = the joining date (Dhaka), and `snapshot` = that month's `RatePlan::snapshot()`.
    *   Inserts the `entry_premium` due, with month = the joining month, the current plan, and `origin = back_dues`.
    *   Writes the activity log `back_dues_created` with the plan's lines.

#### 26. `app/Domain/Members/Actions/CreateMember.php` (Modify)

*   **Proposed Changes**:
    *   Add the parameter `?YearMonth $duesStartMonth = null`, which defaults to `$effectiveFrom`.
    *   Inside the transaction, after `shares->increase(...)`:
        *   call `BackDuesPlanner::plan`;
        *   call `BackDuesWriter::write`;
        *   set `members.dues_start_month`, which is `$effectiveFrom` when the setting is off.

#### 27. `app/Domain/Contributions/Actions/CancelBackDues.php` (Create)

*   **Type / Scope**: Final invokable financial Action.
*   **Proposed Changes**: `__invoke(User $actor, Member $member, string $reason): int`.
    *   **Order of work:** inside `DB::transaction(attempts: 3)`, lock the member and then the member's `back_dues` dues.
    *   **Refusals:**
        *   any of those dues has `paid_poisha > 0` → `dues.errors.back_dues_paid`;
        *   the actor created the member → `dues.errors.cancel_own_back_dues`.
    *   **Effect:** sets the dues to `cancelled`, requires a reason of at least 5 characters, and writes the activity log. It does not change `dues_start_month`; the member's position simply no longer shows those months as owed.
    *   **Who may do it:** a policy ability `cancelBackDues` on `MemberPolicy`.

#### 28. `app/Domain/Contributions/Services/DuePosting.php` (Modify)

*   **Proposed Changes**: map `DueType::EntryPremium` to `AccountCode::ENTRY_PREMIUM_INCOME`.

#### 29. `app/Domain/Contributions/Actions/ApplyLateFees.php` (Modify)

*   **Proposed Changes**: replace both `opening = false` filters (lines 50 and 148) with `origin = 'regular'`.

#### 30. `app/Domain/GoLive/Actions/PostOpeningImport.php` (Modify)

*   **Proposed Changes**:
    *   Write `'origin' => DueOrigin::Opening` instead of `'opening' => true`.
    *   Set `dues_start_month` = the go-live month on the members it creates.

#### 31. `app/Domain/Integrity/Checks/DueSnapshots.php` (Modify, if needed)

*   **Proposed Changes**: make sure back-dues' snapshots are compared against their own month's plan. The writer already stores that, so this is expected to be a test-only confirmation.

#### 32. `app/Filament/Resources/Members/Schemas/MemberForm.php` (Modify)

*   **Proposed Changes**:
    *   Add a "Dues start month" month picker, shown only when `back_dues_enabled` is on. It defaults to the go-live month and refuses months outside go-live to share start.
    *   Add a live breakdown placeholder that renders `BackDuesPlanner::plan()`: one row per month, then the registration fee, the premium and the total.

#### 33. `app/Filament/Resources/Members/Pages/CreateMember.php` (Modify)

*   **Proposed Changes**:
    *   Pass `dues_start_month` to the Action.
    *   Include the back-dues total and the premium in the T2 confirmation rows.

#### 34. `app/Filament/Resources/MemberApplications/Actions/RegistrationActions.php` and `app/Domain/Members/Registration/Actions/DecideRegistration.php` (Modify)

*   **Proposed Changes**:
    *   **Staff side:** the final approval step asks for the dues start month (same field and preview as above), and `activate()` passes it to `CreateMember`.
    *   **Applicant side:** applicants can never set it; the mobile API registration route ignores the field.

#### 35. `app/Filament/Resources/Members/Schemas/MemberInfolist.php` and `app/Filament/Resources/Members/Pages/ViewMember.php` (Modify)

*   **Proposed Changes**:
    *   Show the dues start month and the back-dues total, labelled "from joining".
    *   Add the header action "Cancel back-dues" (T3, through `ConfirmsWithTier`), visible only while the member's back-dues are unpaid.

#### 36. `lang/en/members.php`, `lang/bn/members.php`, `lang/en/dues.php`, `lang/bn/dues.php`, `lang/en/rates.php`, `lang/bn/rates.php` (Modify)

*   **Proposed Changes**:
    *   Error keys: `dues_start_before_go_live`, `dues_start_after_shares`, `back_dues_paid` and `cancel_own_back_dues`.
    *   Labels for the dues origin and the `entry_premium` due type.
    *   Breakdown table headings.

### Stage 3: One member position and frozen receipts

#### 37. `app/Domain/Members/Portal/MemberPosition.php` (Create; replaces `MemberSummary.php`)

*   **Type / Scope**: Final read service.
*   **Proposed Changes**: `for(Member $member, ?CarbonImmutable $asOf = null): MemberPositionData`. It computes the figures exactly as defined in Technical Plan §3:
    *   `paidThrough`: the latest month where every `deposit` and `service_charge` due with month ≤ M is settled, waived or cancelled; null if the first monthly due is not settled;
    *   `oweNow` and `comingUp`, split on `due_date` against today;
    *   `otherOwed`: open dues of type late_fee, registration or entry_premium;
    *   `paidTotal`: the sum of `amount_poisha` over payments with status approved;
    *   `advance`;
    *   `savings`;
    *   `monthlyAmount`;
    *   `advanceCoversMonths`;
    *   the 12 `months`.

#### 38. `app/Domain/Members/Portal/MemberPositionData.php` and `app/Domain/Members/Portal/MonthState.php` (Create)

*   **Type / Scope**: `final readonly class` and a backed enum with `HasLabel`, `HasColor` and `HasIcon`.
*   **Proposed Changes**:
    *   The DTO holds `Money` fields only, plus `?YearMonth $paidThrough` and `list<array{month: YearMonth, state: MonthState, owed: Money}> $months`.
    *   The enum has the 8 month states.

#### 39. `app/Domain/Members/Portal/MemberSummary.php`, `app/Domain/Contributions/Services/PaidThroughCalculator.php` (Delete)

*   **Proposed Changes**: their logic moves into `MemberPosition`. Every caller listed below is switched over first.

#### 40. `app/Domain/Contributions/Services/PaymentPreviewer.php` (Modify)

*   **Proposed Changes**:
    *   Remove `coversThrough()` (it uses `MemberPosition::advanceCoversMonths`).
    *   `preview()` first allocates the member's other pending payments (oldest received first) against a copy of the open dues, then this amount.
    *   Return `pendingCounted: int` and `stillOwedAfter: Money`.

#### 41. `app/Domain/Contributions/Actions/ApprovePayment.php` (Modify)

*   **Proposed Changes**:
    *   Before allocating, read `MemberPosition` and store `outstanding_before`.
    *   After settling and writing the advance, read it again and store the four "after" figures.
    *   Replace the null-plan property read with `members.errors.no_rate_plan`.

#### 42. `app/Domain/Contributions/Actions/ApplyAdvance.php` (Modify)

*   **Proposed Changes**: drop the `due_date <= end of month` filter at line 80, so it settles any open, already-billed due, oldest first.

#### 43. `app/Domain/Contributions/Actions/ReversePayment.php`, `app/Domain/Contributions/Actions/ReverseAdvanceRefund.php` (Modify)

*   **Proposed Changes**:
    *   As the last step inside the transaction, call `ApplyAdvance([$memberId], $actor)`. The member is already locked, so this is safe under the lock order.
    *   Neither Action touches the frozen receipt columns.

#### 44. `app/Domain/Contributions/Actions/RecordPayment.php`, `app/Domain/Contributions/Data/PaymentData.php` (Modify)

*   **Proposed Changes**:
    *   Add `?string $duplicateReason` to the DTO.
    *   In the Action, inside the advisory-locked transaction: for cash or bank, if `SimilarPayments::to(...)` is not empty and there is no reason of at least 5 characters, throw `payments.errors.duplicate_needs_reason`. Store the reason.
    *   After creating the payment, send the "received, waiting for approval" SMS.

#### 45. `app/Reports/ReceiptDocument.php`, `resources/views/reports/pdf/receipt.blade.php` (Modify)

*   **Proposed Changes**:
    *   Print owed before, received, owed after, other amounts owed, advance and paid through from the frozen columns, under the "এই রসিদের সময়ে" (as at this receipt) heading.
    *   For legacy payments, use the live `MemberPosition` with the heading "as at print date".

#### 46. `app/Domain/Notifications/Enums/SmsTemplateKey.php`, `database/seeders/SmsTemplateSeeder.php`, `app/Domain/Notifications/Listeners/SendPaymentReceiptSms.php` (Modify), plus a data migration `database/migrations/2026_11_23_000005_add_payment_recorded_sms_template.php` (Create)

*   **Proposed Changes**:
    *   Add the `PaymentRecorded` template.
    *   The receipt SMS reads the frozen figures: "৳X received, paid through <month>, still owed ৳Y".
    *   The migration inserts the new template on existing databases.

#### 47. `app/Http/Controllers/Api/Member/DashboardController.php` (Modify)

*   **Proposed Changes**:
    *   Return the `MemberPosition` fields, including `months`.
    *   Keep the old keys (`outstanding`, `due_this_month`, `due_earlier`, `billed`, `paid`, `waived`, `monthly_deposit`) for one release, mapped from the new figures, so the mobile app does not break.
    *   Document both the old and new keys in the API spec.

#### 48. `app/Domain/Integrity/Checks/StrandedAdvance.php`, `app/Domain/Integrity/Checks/DuesCompleteness.php`, `app/Domain/Integrity/Checks/ReceiptFigures.php` (Create); `app/Domain/Integrity/Actions/RunIntegrityChecks.php` (Modify)

*   **Proposed Changes**: implement the three checks as SQL aggregates, matching the existing `IntegrityCheck` interface, register them in the run list, and add their labels to `lang/*/integrity.php`.

### Stage 4: Screens

#### 49. `app/Filament/Member/Widgets/MemberPositionWidget.php` (Create), `resources/views/filament/member/widgets/member-position.blade.php` (Create), `app/Filament/Member/Widgets/MemberStatsWidget.php` (Delete), `app/Filament/Member/Pages/Dashboard.php` (Modify)

*   **Proposed Changes**:
    *   A summary card as described in Technical Plan §9, built with Tailwind classes, phone first, with no horizontal scroll at 360 px.
    *   Month chips: each one has an icon, a short label and an `aria-label`.
    *   A "details" disclosure holds savings, the share count, advance coverage and the waiting-for-approval line.
    *   Money is formatted with a new helper, `Display::moneyShort()`, which drops ".00" when there are no poisha.

#### 50. `app/Filament/Support/Display.php` (Modify)

*   **Proposed Changes**: add `moneyShort(Money $money): string`.

#### 51. `app/Filament/Member/Pages/Dues.php` (Modify)

*   **Proposed Changes**:
    *   No default status filter.
    *   Group rows by month, one per month (the total, plus an expandable breakdown by type).
    *   Add a "partly paid" badge when 0 < paid < amount, and an origin badge for back-dues and opening arrears.
    *   Columns on phones: month, owed and status only.

#### 52. `app/Filament/Member/Pages/Payments.php` (Modify)

*   **Proposed Changes**: add a "for months" column, built from the payment's allocations, for example "আগ–সেপ ২০২৬" (Aug–Sep 2026). Eager-load the allocations with their due, to avoid N+1 queries.

#### 53. `app/Providers/Filament/MemberPanelProvider.php` (Modify)

*   **Proposed Changes**: default the member panel locale to `bn` when the user has no saved locale.

#### 54. `app/Filament/Pages/Collections/CollectPayment.php`, `resources/views/filament/pages/collections/collect-payment.blade.php` (Modify)

*   **Proposed Changes**:
    *   **Layout:** the member select at the top, then the position panel (owe now, paid through, advance, month chips with "clear up to here" amounts, one row per month with an expandable breakdown), then amount / method / date, then a collapsible "more" for proof and notes. The Record button moves into the form footer.
    *   **Preview:** labelled "expected — final on approval", with the pending count and "still owed after".
    *   **Duplicates:** when `similarPayments()` is not empty, a required "Reason (possible duplicate)" textarea appears and is sent as `duplicate_reason`.

#### 55. `app/Filament/Resources/Payments/Tables/PaymentsTable.php`, `app/Filament/Resources/Payments/Actions/PaymentActions.php`, `app/Filament/Resources/Payments/Schemas/PaymentInfolist.php` (Modify)

*   **Proposed Changes**:
    *   A "Possible duplicate" badge and filter (`duplicate_reason` is not null).
    *   The approval dialog shows the actual allocation it is about to post and the duplicate reason.
    *   The infolist shows the frozen figures.

#### 56. `app/Filament/Widgets/SomitiOverviewWidget.php`, `app/Domain/Reporting/SomitiSnapshot.php` (Modify)

*   **Proposed Changes**:
    *   Add `billedIn(YearMonth)`, `owedNow()`, `advanceHeld()` and `pendingCount()` to the snapshot.
    *   Add a "this month" row: billed, collected (with % of billed), still owed, advance held, waiting for approval. Each tile's description says what it counts.

#### 57. `lang/en/portal.php`, `lang/bn/portal.php`, `lang/en/payments.php`, `lang/bn/payments.php`, `lang/en/dashboard.php`, `lang/bn/dashboard.php`, `lang/en/integrity.php`, `lang/bn/integrity.php` (Modify)

*   **Proposed Changes**: every new string in both languages. The member wording uses plain Bangla:
    *   "আপনার বকেয়া" (what you owe);
    *   "আপনি দিয়েছেন" (you have paid);
    *   "অগ্রিম" (advance);
    *   "অন্যান্য বকেয়া" (other amounts owed);
    *   "যোগদানের আগের মাস" (months before joining);
    *   "প্রবেশ প্রিমিয়াম" (entry premium).

#### 58. Existing tests to update (Modify)

*   **Proposed Changes**:
    *   `tests/Feature/Members/MemberSummaryTotalsTest.php` is renamed to `MemberPositionTotalsTest.php`, with the same totals asserted under the new names.
    *   `tests/Feature/Api/ApiDashboardProfileTest.php`: the new fields, and the old keys still present.
    *   `tests/Feature/Contributions/AdvanceScenariosTest.php`: paid-through under the new definition.
    *   `tests/Feature/Contributions/PaymentPagesTest.php`: the collect page layout, preview labels and the duplicate reason field.
    *   `tests/Feature/Portal/PortalTest.php`: the home widget and receipt.
    *   `tests/Feature/GoLive/PostOpeningImportTest.php` and `tests/Feature/GoLive/OpeningEngineGuardsTest.php`: `origin = opening` instead of `opening = true`.
    *   `tests/Feature/Contributions/LateFeesTest.php`: back-dues are skipped.
    *   `tests/Arch/*`: the new Actions are final, and the DTOs are readonly.

### Stage 5: Documents

#### 59. `SOMITI_SPEC.md` (Modify)

*   **Proposed Changes**:
    *   Business rules:
        *   add BR-8a (dues start month, back-dues, entry premium, no fines on back months);
        *   amend BR-13 (receipt content and the paid-through definition);
        *   state in BR-21 that share-months come only from share lots.
    *   §2 and §4: the member portal is the Filament panel at `/portal`.
    *   §5.5: the single paid-through definition.
    *   §6.7: the three new invariants.
    *   §9: the new columns.
    *   Add new §10 steps after the last completed phase, without rewriting completed phases.

#### 60. `docs/financial-calculation-rules.md`, `docs/known-gaps-and-risks.md`, `docs/testing-and-acceptance-criteria.md`, `docs/dashboard-by-role.md`, `docs/module-and-page-guide.md`, `docs/user-manual-bn.md`, `docs/user-manual-en.md` (Modify)

*   **Proposed Changes**:
    *   The rules for back-dues, the premium, paid-through, owe now and the frozen receipt.
    *   Close the gaps this resolves, in `known-gaps-and-risks.md`.
    *   Manual pages for "adding a late joiner" and "reading your home screen".

#### 61. `docs/superpowers/specs/2026-10-05-member-mobile-api-design.md` (Modify)

*   **Proposed Changes**: the new dashboard fields, and the deprecation date for the old keys.

#### 62. `docs/audits/financial-ux-audit.md` (Modify)

*   **Proposed Changes**: mark F-01 to F-08, F-10, F-11, F-13 and F-15 to F-20 as resolved by this design, with the design's path.

### Verification Plan

*   **Build**: `composer install`, `npm run build` (the member widget uses new Tailwind classes), `php artisan migrate` on a copy of the demo database (`php artisan somiti:demo --fresh`, then migrate).
*   **Tests**: `php artisan test --compact`, covering the full suite including `tests/Concurrency` and the 1,000-seed `tests/Invariants/SimulationTest.php`. Extend the simulation to add random late joiners with back-dues and premiums.
*   **Static analysis and style**: `composer analyse` (Larastan level 8, no baseline) and `composer format:check` (Pint).
*   **Integrity**: `php artisan somiti:integrity:check` on the demo before and after migrating, with zero findings both times, and on a production copy before deploy.
*   **Manual acceptance**:
    *   On a 360 px phone, the member home shows what is owed without scrolling (the 5-second target is timed by hand with two committee members).
    *   A cashier records a cash payment in three actions after finding the member.
    *   A reprinted receipt is identical to the first print.

--------------------------------------------------------------------------------

### Additional Resources

*   `docs/audits/financial-ux-audit.md` — evidence and finding IDs (F-01 … F-22)
*   `concepts_brief.md` — brainstorm brief and author decisions
*   `SOMITI_SPEC.md` — master specification
*   `docs/known-gaps-and-risks.md`

### Future Improvements

*   **Profit-based entry premium:** replace the fixed percentage with the profit per share the society actually earned before the member joined.
*   **Reactivated members:** reuse the dues-start mechanism to bill months while inactive (known gap 14).
*   **Single date basis for "collected" figures** (known gap 13).

<!-- Provenance: Indicates initial structural drafting via EGM. -->

*Drafted with EGM*
