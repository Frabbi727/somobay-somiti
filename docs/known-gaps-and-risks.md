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
| Reversing a "lock prepaid months" payment left the locked future dues open at the old rate | The locked dues are cancelled; months already generated get a new due at their own rate, later months are built by the monthly run |
| Reversing an exited member's payment reopened cancelled dues | Refused once the member has exited |
| `FundsGuard` checked the all-time balance, so a back-dated payout could make a past balance negative | The payout must be covered on its own date and every later date |
| A user could hold super admin together with president, accountant or cashier and approve under that role | That combination is refused when creating or editing a user |
| Expense rejection ignored the president threshold | Only those who may approve an expense may reject it |
| One resolution could back several investments, added investment amounts or exits | One live item per resolution (checked across investments and top-ups in the actions, unique indexes in the database); rejected, cancelled and reversed items release it |
| Matched statement lines stayed matched after their voucher was reversed | Reversing a voucher returns its statement lines to the unmatched list |
| President thresholds, quorums, required resolutions and year-end choices could only be changed in the server config | Settings › Financial settings: one person proposes with a reason, someone else approves; history kept, applies without a deploy, year-end choices frozen while a year-end waits |
| Member home screen showed only outstanding, savings and advance | Also total billed, paid, waived, this month's due, earlier months' due and the monthly deposit for the member's shares (portal and app API) |
| An advance refund made by mistake could not be undone | **Collections › Advance Refunds** lists every refund; someone other than whoever made it (with *Reverse advance refunds*) reverses it with a mirror voucher, and the member gets the advance back. Refused once the member has exited |

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
| 2 | No reversal flow for exit approvals, year-end postings, dividend settlements | a mistake needs a manual workaround | design reversal actions one by one |
| 5 | Retroactive rate corrections (BR-7 adjustment dues) not built | none now (refused) | **Decision:** whether needed |
| 6 | Pro-rata dividend for the exit year | none now (decided: no dividend) | revisit only if bylaws require |
| 7 | Refundable share capital (3101) not in the exit settlement (BR-22) | depends on bylaws | **Decision** |
| 9 | Idempotency check in record expense/transfer/investment runs before the transaction (database unique key is the backstop) | rare duplicate error message | move inside a locked transaction |
| 10 | Registration chain stuck if nobody active holds a step's role | applications wait forever | now visible ("nobody can approve"); reminder goes to nobody — president must fix roles |
| 11 | Cash book page uses the collections area and member statement uses cash reports, unlike `Area.php` | who sees two reports differs from the permissions page wording | **Decision:** move them or update the wording |
| 13 | Dashboard "collected this month" counts by receipt date; reports count by voucher date or due month | figures differ when payments are reversed later | explained on the tile and in `financial-calculation-rules.md` |
| 14 | Exited/inactive member reactivated: no dues for the months inactive | missing dues | **Decision:** policy for reactivation |
| 17 | `viewReports` gate and `opening.import` permission are unused | confusion only | remove |

## Operational risks (from the roadmap)

Backups to an off-site disk, monitoring, queue worker and scheduler running on the server (`php artisan schedule:work` / cron and `queue:work`) — without the queue worker SMS are not sent; without the scheduler dues, late fees, reminders and the self-check do not run.
