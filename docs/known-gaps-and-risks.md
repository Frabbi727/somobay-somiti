# Known gaps and risks

What the audit of October 2026 found, what was fixed on branch `ux-roles-approvals`, and what still needs a decision. Items marked **Decision** need the committee or business owner before any code changes.

## Fixed in this round

| Was | Now |
|---|---|
| Super admin could give themselves the president role | Nobody changes their own roles; role/permission changes alert the president |
| President could give the super admin approve/reverse permissions | Locked in code and cleaned in the database |
| One person could prepare and post a manual voucher | Someone other than the author or last editor posts |
| The approver of a payment/expense/transfer could reverse it | Refused (policy, action and database) |
| The accountant who prepared a year-end could also sign it | Refused |
| **Exited members kept earning dividends** (shares never ended) | Approving an exit ends all shares; exited members get no dividend for the exit year or later; a nightly check catches any left |
| "Retroactive" rate plans could be approved but did nothing | Refused until properly designed |
| A duplicate SMS key could abort an approval transaction on PostgreSQL | SMS insert runs in its own savepoint |
| Only registrations told approvers; nobody heard outcomes; members were not told about rejected/reversed payments | Bell messages for every queue and outcome; SMS + portal message to members on rejection/reversal; daily reminder |
| Generic error texts; raw exception text in two previews; no reference | Plain messages saying whether anything was saved; error reference in logs, pages and API |
| Dashboard the same for everyone; no "who has my entry" | Role-aware parts, "What you entered" with names, alerts, explained figures, history on record pages |
| Overdue count differed from the Defaulters report | Same rule |
| Bangla status words differed between modules | One set of words |

## Data to check on the live system (one-off)

Run on production **before** the next year-end (dry run first; nothing is saved without `--apply`):

```
php artisan somiti:exits:end-share-lots          # shows exited members whose shares never ended
php artisan somiti:exits:end-share-lots --apply  # ends them
```

It also lists any dividend lines already posted to members after they exited. **Decision:** whether to pay those, write them back, or leave them — a posted year-end is never changed by code.

## Still open

| # | Gap | Risk | Suggested next step |
|---|---|---|---|
| 1 | Single-person actions: advance refund below the limit, late-fee waiver, dividend settlement, exit payout, investment income/write-down/close, recording a resolution's outcome, closing a fiscal year | one person can move or forgive money | **Decision:** which need a second person |
| 2 | No reversal flow for approved investments, exit approvals/payouts, year-end postings, dividend settlements, advance refunds | a mistake needs a manual workaround | design reversal actions one by one |
| 3 | Reversing a payment approved under "lock prepaid months" leaves the locked future dues open at the old rate | wrong dues after a reversal | fix in `ReversePayment` (cancel prepaid-locked dues) |
| 4 | Reversing a payment of an exited member reopens cancelled dues | exited member shows dues again | refuse reversal after exit, or handle it |
| 5 | Retroactive rate corrections (BR-7 adjustment dues) not built | none now (refused) | **Decision:** whether needed |
| 6 | Pro-rata dividend for the exit year | none now (decided: no dividend) | revisit only if bylaws require |
| 7 | Refundable share capital (3101) not in the exit settlement (BR-22) | depends on bylaws | **Decision** |
| 8 | Expense *rejection* ignores the president threshold (anyone who may approve small expenses can reject a large one) | low | align `ExpensePolicy::reject` |
| 9 | Idempotency check in record expense/transfer/investment runs before the transaction (database unique key is the backstop) | rare duplicate error message | move inside a locked transaction |
| 10 | Registration chain stuck if nobody active holds a step's role | applications wait forever | now visible ("nobody can approve"); reminder goes to nobody — president must fix roles |
| 11 | Cash book page uses the collections area and member statement uses cash reports, unlike `Area.php` | who sees two reports differs from the permissions page wording | **Decision:** move them or update the wording |
| 12 | `FundsGuard` uses the all-time balance, not the balance on the entry date | a back-dated payout can make a past balance negative | check balance as of date |
| 13 | Dashboard "collected this month" counts by receipt date; reports count by voucher date or due month | figures differ when payments are reversed later | explained on the tile and in `financial-calculation-rules.md` |
| 14 | Exited/inactive member reactivated: no dues for the months inactive | missing dues | **Decision:** policy for reactivation |
| 15 | Investment and exit resolutions can be reused for several items | weak governance link | make resolution links unique |
| 16 | Matched statement lines are not updated when their voucher is reversed | reconciliation drift | unmatch on reversal |
| 17 | `viewReports` gate and `opening.import` permission are unused | confusion only | remove |
| 18 | A user holding both super admin and a committee role can still approve under the committee role | the super-admin lock is per role | **Decision:** forbid combining super admin with president/accountant/cashier |

## Operational risks (from the roadmap)

Backups to an off-site disk, monitoring, queue worker and scheduler running on the server (`php artisan schedule:work` / cron and `queue:work`) — without the queue worker SMS are not sent; without the scheduler dues, late fees, reminders and the self-check do not run.
