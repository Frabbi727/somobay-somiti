# Testing and acceptance criteria

## How to run the checks

```bash
composer test           # full Pest suite (PostgreSQL test database)
composer analyse        # Larastan level 8, no baseline
composer format:check   # Pint
```

Manual testing with one login per role: `docs/LOCAL_TESTING.md`.

## Results on branch `ux-roles-approvals` (11 October 2026)

| Check | Result |
|---|---|
| `composer test` | 1,028 tests, 60,302 assertions, all passing |
| `composer analyse` | 0 errors |
| `composer format:check` | passing |
| `somiti:exits:end-share-lots` dry run on the local demo data | ran, nothing to fix |
| Browser check (local demo, president) | dashboard sections and payment History render correctly; the rest of the role-by-role walkthrough below is still to be done by a person |

## What the automated tests cover

### Roles and permissions
- The super admin can never be given an approve/reverse/post permission (one test per such permission).
- Nobody changes their own roles; the last super admin stays.
- The last registration approver keeps "add members".
- Payment/expense/transfer reversal by the approver is refused (policy, action, database CHECK).
- A manual voucher cannot be posted by its author or last editor (policy, action, database trigger).
- The accountant who prepared a year-end cannot sign it (action, database CHECK).
- Existing: each role opens exactly its pages (`RoleAccessTest`); every action has the right confirmation tier (`ActionInventoryTest`).

### Approvals and money
- Approving an exit ends all shares from the next month and records an "exit" share change.
- Exited members are left out of the year's share-months (no dividend).
- An exit with a share change planned after the exit month is refused.
- The repair command saves nothing without `--apply`, and fixes old exits with it.
- Retroactive rate plans are refused; old retroactive drafts follow the normal month rule.
- A duplicate SMS key no longer aborts the surrounding transaction.
- Existing: payment allocation, advance, late fees, year-end, concurrency (double approval, idempotency, voucher numbers) and a randomised simulation that the books always tie out.

### Notifications
- A new payment reaches only the people who can approve it, in each person's language, with a link; deactivated users are skipped; nothing is sent when the action rolls back.
- Approve/reject/reverse reach the right staff and the member (portal bell; SMS with reason on reject/reverse).
- Expenses, vouchers, exits, registrations (link, applicant messages) and role changes notify the right people.
- The daily reminder goes once per person per day and can be switched off.
- Failed SMS can be resent by the right people; login codes never.

### Errors
- Business rule, permission, concurrency (40001, 40P01, 55P03, 23505) and database-rule (23514) failures each give their own plain message and halt; other errors are left to the general handler.
- Every request has one reference in the log context; API server errors return it and never the technical message; error pages show both languages.

### Dashboard and history
- The approval queue lists items only for people the policy allows; "who can act" names them.
- "What you entered" shows who it is waiting for, and drops it once approved; warns when nobody can approve.
- Registration and voucher tiles appear; each role gets its own quick buttons and widgets.
- Overdue members equal the Defaulters report.
- History shows only steps that happened (entered → rejected with reason and time).
- Members see payments being checked and turned down (with reason) on the portal.

### Language
- Every key exists in both `lang/bn` and `lang/en` with the same placeholders (one test per language file).

## Acceptance walkthrough (manual, before deploying)

Use the demo logins from `LOCAL_TESTING.md` (password `password`).

1. **Cashier** records a cash payment → the accountant's bell shows "A payment is waiting for your approval"; the cashier's home page shows it under **What you entered** with the accountant's and president's names.
2. **Accountant** approves it → the cashier's bell says approved; the member's portal bell says received; History shows both steps.
3. Record another and **reject** it with a reason → the member sees the reason on the portal home page and receipts; an SMS row with the reason is in the SMS log.
4. **The same accountant** tries to reverse the first payment → the Reverse button is missing; as `accountant2` it works and the cashier and the first accountant are told.
5. **Accountant** prepares a voucher draft → `accountant2` gets the bell and can post it; the author cannot.
6. **Super admin** edits their own account → the roles are locked; editing another user's roles sends the president a bell message.
7. **President** opens Role permissions → the super admin column shows "—" for approve/reverse/post permissions.
8. **Auditor** home page has no "What you entered", has Needs attention, and only the Defaulters and Audit log buttons.
9. Switch language to English and back; check a phone-width window: buttons fold into "More", lists stay readable.
10. Open `/no-such-page` → the friendly bilingual page.
11. On production, run `php artisan somiti:exits:end-share-lots` (dry run) and decide on anything it lists.
