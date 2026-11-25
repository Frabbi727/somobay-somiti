# Late data entry of a new member — design

**Date:** 2026-11-25 · **Size:** small change

## Intent

A member joined the somiti in October 2026 and paid from October, but is only being entered
into the app in November, after dues were already generated up to December. Today the app
refuses ("Dues already exist up to 2026-12, so shares cannot change from 2026-10"), because
BR-1 forbids a share change in a month that already has dues. For a **brand-new member** that
rule protects nothing: they have no dues yet, so adding theirs changes no one else's.

The office must be able to enter such a member exactly as they are in real life: a normal
member from their real joining month.

## Goals

- When creating a new member, "Shares count from" may be a month that already has dues.
- The app creates that member's **normal** monthly dues (deposit + service charge) for every
  already-generated month from their share start up to the latest generated month, at each
  month's approved rate.
- No entry premium, and no late fee for those months.
- Shares (and so dividends) count from the real joining month.
- The form shows the allowed range up front instead of failing after the whole form is filled.

## Non-goals

- Changing shares of an **existing** member in a generated month. BR-1 stays as it is for
  `ChangeShares`, decreases and exits.
- Recording the payments the member already made. The office records them afterwards through
  the normal Collect Payment flow.
- Changing the real late-joiner flow (back-dues + entry premium). It stays as it is.

## Anti-goals

- Any existing due, journal or other member's balance changing.
- A late-entry member ending up in a dividend that has already been worked out without them, or
  in a closed year.

## Rules (the guard that replaces BR-1 for a new member)

A new member's share start month `S` is allowed when all of these hold:

1. `S` is not before the go-live month / society start (same check back-dues uses).
2. The fiscal year containing `S` is open.
3. No year-end (dividend) has been prepared or approved for the fiscal year containing `S`.
4. There is an approved rate plan for `S` (already true for every generated month).

Each failure throws `DomainRuleViolation` with its own bn/en key, saying which month and why.

## Approaches considered

| Approach | Trade-off | Verdict |
|---|---|---|
| **A. Late entry: lift BR-1 for new members only, create their missing regular dues** | Correct shares and dividends from the real month; small, contained change | **Picked** |
| B. Use the back-dues path (shares from Jan 2027, dues start Oct) | No code change, but the member loses 3 months of dividend and pays an entry premium they don't owe | Rejected: wrong for this member |
| C. Let staff re-run the monthly dues generation for Oct–Dec after creating the member | Reuses the idempotent run, but it's a manual step that's easy to forget, and the dues would get past due dates, so late fees would apply immediately | Rejected |

## Design

- **`ShareChanger`**: a new method for a joining lot (used only by `CreateMember`) that applies
  the rules above instead of the "month already generated" check. `increase()` for existing
  members keeps BR-1 unchanged.
- **`CreateMember`**: after the joining lot is created, for each month from `S` to the latest
  generated month, build that member's rows with `MonthlyDueBuilder::rows()` (the same
  amounts, rate plan and snapshot the monthly run would have made). Each row gets
  `origin = Regular` and `due_date` = the entry date, so the normal grace period starts from
  today and no late fee is charged for the delay. The insert uses the dues natural key, so a
  later re-run of the monthly generation adds nothing twice.
- **Works together with back-dues**: a late joiner who is also entered late gets back-dues for
  months before `S` (unchanged) plus these catch-up regular dues from `S` onward.
- **Registration approval** (`RegistrationActions`) uses the same rule and the same Action,
  so approving an application with an earlier share month behaves the same way.
- **Form** (`MemberForm`, registration approval form): the default stays as the first open
  month. The helper text shows the earliest allowed month, and a preview line lists the
  already-billed months that will be added ("Oct, Nov, Dec 2026 will be added as normal dues:
  ৳X"). The money shown comes from the server; no JS calculation.
- **Activity log**: one `late_entry_dues_created` entry on the member with the months and total.
- **Translations**: new strings in `lang/en` and `lang/bn`.

## Tests

- Happy path: generated up to 2026-12, create a member from 2026-10. Lot from 2026-10, regular
  dues for Oct/Nov/Dec at each month's rate, due date = today, no entry premium.
- Re-running generation for 2026-10..12 creates nothing new for that member.
- No late fee is charged for those dues on today's run.
- Each rule violation: before go-live, closed fiscal year, year-end prepared, no rate plan.
- `ChangeShares` on an existing member in a generated month still fails with `month_generated`.
- Combined with back-dues: back-dues before `S`, regular catch-up dues from `S`.

## Left to the builder

- The exact name of the new `ShareChanger` method and the translation keys.
- Where the preview line is computed (a small read-only service, like `BackDuesPreview`).
