# Module and page guide

Every page in the app: what it is for, who uses it, whether it needs approval, whether it changes money, and where to see the result. "Approval: yes" means the entry waits for a second person; until then nothing is recorded in the books.

Every button that saves something asks for confirmation first: a simple yes/no for small changes, a summary of what will change for member/settings/payment entries, and typing a word or number for approvals, reversals and anything financial.

## Staff panel (`/admin`)

### Home
| Page | Purpose | Who | Approval | Money | Status / result |
|---|---|---|---|---|---|
| Dashboard | Your tasks, your entries and who has them, problems, headline figures | all staff | — | no | see `dashboard-by-role.md` |
| Bell 🔔 (top right) | Messages about your work, each with an Open button | all staff | — | no | read/unread history |

### Members
| Page | Purpose | Who | Approval | Money | Status / result |
|---|---|---|---|---|---|
| Members | List, search, view members; add, edit, change shares, deactivate/reactivate, set portal password | view: all staff; changes: secretary, president | no (share changes take effect from a month without dues) | adding shares creates registration-fee dues | member page |
| Member applications | Self-registrations: invite a mobile, review, approve / send back / reject at your step | secretary, president (by approval order) | yes, step by step | final approval creates the member and registration fee due | application page timeline; bell |
| Member exits | Request, approve and pay out an exit | request: secretary, president; approve: president; pay: accountant, president | yes | approval settles the member's accounts; payout pays cash/bank | exit page History |

### Collections
| Page | Purpose | Who | Approval | Money | Status / result |
|---|---|---|---|---|---|
| Collect payment | Pick a member, see dues and a preview of what the payment settles, record it | cashier, accountant, president | yes (checker) | only after approval | Payments list; "What you entered" |
| Payments | All payments: approve, reject, reverse, cancel own pending, print receipt | view: collections area; approve/reverse: accountant, president | — | approve/reverse post vouchers | payment page History |
| Dues | Every due with status; waive an unpaid late fee | collections area; waive: accountant, president | no | waiver reduces a late fee (single person) | due row |
| Generate dues | Create the month's dues (also runs automatically on the 1st) | accountant, president | no | creates dues (no voucher) | bell when finished |
| Advance balances | Members' advance; apply now; refund | collections area; apply: accountant, president; refund: same, president above limit | no | apply/refund post vouchers | member statement |
| Expenses | Record, approve, reject, reverse expenses | record: cashier+; approve: accountant/president (president above limit) | yes | after approval | expense page History |
| Fund transfers | Move money between cash, bank, bKash, Nagad | as expenses | yes | after approval | transfer page History |

### Accounting
| Page | Purpose | Who | Approval | Money | Status / result |
|---|---|---|---|---|---|
| Chart of accounts | Accounts list; add/edit/deactivate | accounting area; changes: accountant, super admin | no | no | — |
| Voucher drafts | Prepare manual vouchers; someone else posts them | accountant, president | yes (second person posts) | after posting | drafts list; bell |
| Vouchers | All posted vouchers; reverse a manual one | accounting area; reverse: accountant, president | no | reversal posts a mirror voucher | voucher page |
| Fiscal years | Open years, lock/unlock months, close a year | accountant (open), accountant/president (lock) | no | locks stop postings | — |
| Statements | Import bank/wallet statements and match lines | accountant, president | no | no (matching only) | unmatched count on the dashboard |
| Investments | Record, approve, income, write-down, close | record: accountant, secretary, president; approve: president | yes (to start) | after approval | investment page History |
| Year-end closing / Year-ends | Prepare the year-end, two approvals, then pay or credit dividends | prepare: accountant, president; approve: president + another accountant | yes (two) | posting closes the year | year-end page History |

### Governance
| Page | Purpose | Who | Approval | Money | Status / result |
|---|---|---|---|---|---|
| Meetings | Schedule, take attendance, hold or cancel | secretary, president | no | no | meeting page |
| Resolutions | Propose, record votes, withdraw | secretary, president | no (recorded at a held meeting with quorum) | no | resolution page |

### Reports (print / Excel)
Trial balance, Account ledger, Balance sheet, Income statement, Receipts & payments, Investment register (financial reports area); Member statement, Collection summary, Defaulters, Cash book (cash reports / collections area); Share register, Dividend register (member reports area); Integrity report (accounting area; run now: accountant, super admin, auditor). Reports never change anything.

### Administration
| Page | Purpose | Who |
|---|---|---|
| Society profile | Name, address, logo used on receipts and SMS | super admin, president |
| Registration approvals | Order of approvers for self-registration | super admin, president |
| Rate plans | Draft, submit, approve (president + secretary), cancel prices | draft: secretary, accountant, super admin |
| Role permissions | Which role may do what (read-only for super admin and auditor) | president |
| Staff users | Create, edit, deactivate staff accounts (nobody edits their own roles) | super admin |
| SMS templates / SMS log | Edit SMS texts; see every SMS and send failed ones again | super admin, secretary |
| Nominee relations | List of relations offered for nominees | members area |
| Backups | Back up now, download, restore (with a safety backup first) | super admin |
| Audit log | Every change, who made it and when | super admin, president, auditor |

## Member portal (`/portal`) and mobile app

| Page | Purpose | Notes |
|---|---|---|
| Dashboard | Alerts (payments being checked, turned-down payments with reason), savings, paid through, outstanding, advance, recent payments, Pay now | Pay now only when something is owed |
| Dues | Each month's dues and what is still owed | — |
| Pay online | Report a bKash/Nagad payment with its TrxID (proof photo optional) | waits for the office's approval; nothing changes until then |
| Receipts | All payments with status and what it means; download receipts for approved ones | rejected/cancelled show the reason |
| Statement | Full statement (PDF) | — |
| Dividends | Dividend lines and whether paid or credited | — |
| Profile | Contact details and password | — |
| Registration / Registration status | For applicants: fill and submit; follow each approval step | returned registrations show the reason |
