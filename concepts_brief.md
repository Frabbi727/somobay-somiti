# Somiti money accuracy and member/collector usability

* **Author:** Rabbi
* **Date:** 2026-11-22

## Overview

Make the Somiti app answer three questions for every member on a phone, in Bangla, within five seconds: how much do I owe, how much have I paid, how much advance do I have. Every one of those numbers must trace back to the ledger. The full evidence-based audit is in `docs/audits/financial-ux-audit.md`. This brief records the concept and the decisions it depends on.

The audit found no calculation bug. Allocation, part-payments, advance, reversals and rate changes all matched hand calculations, and the concurrency and 1,000-seed invariant tests pass. The work is about one missing rule (back-dues for late joiners), records that change after the fact (receipts), figures that are defined in more than one place (paid-through, outstanding), and screens that bury the three numbers that matter.

## Analysis

* **Who owes vs. who owns.** Dues and dividend share-months both come from `share_lots.effective_from`. Shares cannot be backdated (`ShareChanger.php:196`), and backdating would inflate dividends anyway. Resolution: a separate `members.dues_start_month`. A back-dues Action, with a preview, creates deposit dues for each back month at that month's rate and never touches share lots.
* **Back-dues vs. late fees.** Back-dues must keep their real month, but the late-fee run counts from each due's date, so it would charge every back month on day one. Back-dues need a due-origin marker plus either an exemption or a fresh grace window. This is a committee decision.
* **No month picking vs. explaining months.** Collectors enter only an amount, and payments settle oldest first (BR-13). Allocation happens at approval, so the preview is an expectation. Label it that way, count other pending payments, and show any difference to the approver.
* **Frozen record vs. live figures.** Receipts recompute paid-through when printed. Store "due before / due after / paid through / advance after" at approval and print from those stored figures.
* **One position, one definition.** Paid-through is defined three ways today, and "current outstanding" includes months not yet due. One member-position read model should feed the portal, API, collect preview, receipt, SMS and dashboard.
* **No stranded advance.** Reversals reopen dues without re-applying advance. Re-apply advance inside each reversal and add a nightly check for it.
* **Ideal final result:** collectors enter only amounts, members see only months, and the nightly checks prove that the two match the ledger with zero findings.

## Goals vs. Non-Goals

* **In-Scope Goals:**
  * Dues start month and back-dues at sign-up (both creation paths), with a total shown before confirming.
  * Receipt snapshot taken at approval; re-apply advance after every reversal; one rule for which dues can be paid early.
  * One member-position read model and one written definition of paid-through.
  * A phone-first Bangla home screen with three figures and a month strip; dues and receipts pages that show months; distinct status badges.
  * Collect page reordered: dues first, amount next, save button at the end. Duplicate cash entries need a reason.
  * The 14 scenarios and the new nightly checks committed as tests before the build.
* **Out-of-Scope Non-Goals:**
  * A new Livewire portal: the Filament panel at `/portal` is the member UI; fix the spec text instead.
  * Rebuilding allocation, a payment "edit" flow, or retroactive rate corrections.
  * Deposit or service-charge waivers, unless the bylaws call for them.
  * Accruing receivables in the general ledger: stay cash-basis and add sub-ledger tie-out checks.

## Alternatives Weighed

* **Backdate share lots to create back-dues:** rejected. The app refuses it, and it would inflate dividend share-months.
* **Re-run monthly generation for past months:** rejected. It filters on the member's current status, refuses months before go-live, and has no preview at sign-up.
* **Let collectors pick months:** rejected by the author. It allows skipping months that carry late fees and breaks the meaning of paid-through. The "clear up to here" amounts already cover the need.
* **Separate `BackDeposit` due types vs. an origin column on dues:** leaning towards the origin column, because fewer readers need to change.
* **Record receivables in account 1201:** rejected for now. It changes accounting policy; per-member tie-out checks give the same assurance.

## Key Decisions & Staging for Next Steps

**Decided by the author (2026-11-22):**

1. **Late joiners pay every month from the society's start month,** meaning the go-live month. The app has no rates before go-live, so a dues start month can never be earlier (reconciled with the design, 2026-11-22). For each back month they pay that month's deposit and service charge. The price is the rate plan that applied in that month, so if the rate went from ৳500 to ৳600, each back month uses its own rate. This rate rule is assumed by default; the author has not objected.
2. **The registration fee is charged once**, at joining, per share. It is not charged per back month.
3. **Back months get no late fines.** Back-dues are marked with their own origin and the nightly late-fee run skips them.
4. **There is an extra "entry premium" (fairness charge), calculated as a percentage of the back-month **deposits** only (option A; service charge and registration fee are excluded, reconciled with the design).** The percentage is a financial setting: one person proposes it and a second person approves it, the same as the other financial settings. Example: 5 back months × ৳1,000 = ৳5,000; at 10% the premium is ৳500.
   - The premium is society income, not the member's savings. It needs a new account, proposed as **4141 Entry Premium Income / প্রবেশ প্রিমিয়াম আয়**, next to 4131 Exit Fee Income. That way the money benefits the existing members.
   - Back-deposits go to the member's savings (2101) but **do not earn dividend for the back months**. Dividends stay weighted by actual share-months, which already prevents a late joiner from sharing profit earned before they joined. The premium covers what share-months cannot: reserves and undistributed gains already built up.
   - Before confirming, the member and the staff member see one breakdown. Example: back deposits ৳5,000 + service charge ৳100 + registration ৳200 + entry premium ৳500 = **৳5,800**.
5. **Option C (a profit-based catch-up) stays a possible later upgrade**, once the society has a full year of results to base it on.

**Still open:**

* The default premium percentage (the committee sets it in settings; 0% until set?).
* Can the dues start month be changed after confirmation? Recommended: no. Corrections go through a cancel action with a second person.
* Should members be told when a payment is recorded, or only when it is approved?
* Which single date basis should "collected this month" use?

**Staging:**

* Stage 0: write the read-model definition, the 14 scenarios and the new nightly checks as failing tests.
* Stage 1: dues start month and back-dues.
* Stage 2: receipt snapshot and expected-allocation preview.
* Stage 3: member home and month strip.
* Stage 4: collect-page polish.
