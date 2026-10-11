# Business overview

Somobay Somiti keeps the books of one cooperative society (সমবায় সমিতি): its members, their monthly savings deposits and service charges, money received and spent, investments, and the year-end profit distribution. It replaces the khata and Excel so that every poisha can be traced from receipt to ledger to report, and members can see their own account.

## The main ideas

1. **Members hold shares.** Each share means a fixed monthly savings deposit plus a service charge, at the prices in the rate plan for that month.
2. **Dues are made every month** (automatically on the 1st) and paid oldest first. Money paid ahead becomes the member's **advance** and is used automatically as new dues arrive.
3. **Two people for every money decision.** One person enters (maker), another approves (checker). Approval writes the entry in the books. The person who approved never reverses it.
4. **Nothing is ever deleted or edited after approval.** Mistakes are reversed with a reason; prices change with a new rate plan from a future month.
5. **The books prove themselves.** Double-entry vouchers, gap-free voucher numbers, and a nightly self-check that the books and every member's sub-ledger add up exactly.
6. **Everyone is told what concerns them.** Approvers get a bell message when something waits for them; the person who entered it hears the outcome; members get an SMS when their payment is turned down.

## Who uses it

Committee and office staff in the staff panel (president, secretary, cashier, accountant, auditor, super admin) and members in the portal / mobile app. See `roles-and-permissions.md`.

## A month in the life of the somiti

1. **1st, 00:30** — dues for the month are created; members' advance is applied; members who owe get an SMS.
2. **Through the month** — the cashier records cash/bank receipts; members report bKash/Nagad payments in the app; the accountant approves them (each approval prints a receipt and sends a receipt SMS). Expenses and transfers go the same way.
3. **After the due day + grace days** — late fees are added automatically if the rate plan has them.
4. **Month end** — the accountant imports bank statements, matches them, and locks the finished month.
5. **Year end (30 June)** — the accountant prepares the year-end; the president and another accountant approve it; dividends are paid or credited to savings.

## Where to read more

| Question | Document |
|---|---|
| Who can do what? | `roles-and-permissions.md` |
| What does each page do? | `module-and-page-guide.md` |
| What happens after I submit? | `approval-workflows.md` |
| How is every amount calculated? | `financial-calculation-rules.md` |
| Who gets which message? | `notification-matrix.md` |
| What is on my dashboard? | `dashboard-by-role.md` |
| An error appeared — what now? | `error-resolution-guide.md` |
| Step-by-step help | `user-manual-bn.md`, `user-manual-en.md` |
| Words used in the app | `business-glossary.md` |
| What is not done yet | `known-gaps-and-risks.md` |
| How it was tested | `testing-and-acceptance-criteria.md` |
