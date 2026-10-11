# Dashboard by role

There is one dashboard for all staff. Each part decides for itself who sees it, from the same permissions and policies that guard the work, so nobody sees figures or tasks that are not theirs. Members have their own dashboard in the portal.

## The parts of the staff dashboard (top to bottom)

| Part | What it shows | Who sees it | Why |
|---|---|---|---|
| **Quick buttons** | Collect payment · Invite a member · Add member · Record expense · Generate dues · Defaulters · Audit log (extra ones fold into "More") | each button only if the user may do it | the everyday jobs, one click away |
| **Waiting for you** | one tile per queue the user works: payments, registrations at my step, expenses, transfers, vouchers to post, investments, rate plans, year-ends, exits to approve, exits to pay, unmatched statement lines, next meeting. Shown at zero too, so "nothing waiting" is visible | anyone whose permissions include that queue | "what must I do today?" |
| **What you entered** | the user's last 8 entries with status and, while waiting, the **names** of the people who can act now (or a warning that nobody can) | anyone who can record payments, expenses, transfers, investments, draft rate plans, request exits or prepare vouchers | "has my entry been approved yet, and who has it?" |
| **Needs attention** | books self-check result (accounting area); SMS not delivered in the last 30 days (messaging area) | only the people who can fix it | problems before they grow |
| **The somiti today** | active members, members overdue (as of today, same rule as the Defaulters report); for money roles also: collected this month (approved payments received 1st–today), members' savings, cash in hand, bank & wallets (ledger balances today) | anyone who can see members; money tiles only for the collections area | the headline numbers, each saying what it is and for which dates |

All counts come from `ApprovalQueue` (`app/Domain/Reporting/ApprovalQueue.php`) — the same source used by the reminder and the "waiting for" names — so the dashboard, the bell and the record pages never disagree.

## What each role sees by default

| Role | Quick buttons | Waiting for you | What you entered | Needs attention | Money figures |
|---|---|---|---|---|---|
| Cashier | Collect payment, Record expense, Defaulters | own payments awaiting approval | ✓ | — | ✓ |
| Accountant | Collect, Record expense, Generate dues, Defaulters | payments, expenses, transfers, vouchers to post, year-ends, exits to pay, statement lines | ✓ | books self-check | ✓ |
| President | Collect, Invite, Add member, Record expense, Generate dues, Defaulters, Audit log | payments, expenses (incl. large ones), transfers, vouchers to post, investments, rate plans, year-ends, exits to approve and to pay, registrations at the president's step, statement lines, next meeting | ✓ | books self-check | ✓ |
| Secretary | Invite, Add member, Defaulters | registrations at the secretary's step, rate plans, next meeting | ✓ (exits, rate plans) | undelivered SMS | — |
| Auditor | Defaulters, Audit log | (nothing to act on) | — | books self-check, undelivered SMS | ✓ |
| Super admin | Defaulters, Audit log | (nothing to act on — never approves money) | ✓ (rate plan drafts) | books self-check, undelivered SMS | ✓ |

(These follow the default permissions; if the president changes them, the dashboard follows automatically.)

## Member portal dashboard

| Part | What it shows |
|---|---|
| Alerts | payments still being checked by the office ("you do not need to pay again"); payments rejected or cancelled in the last 30 days, with the reason and a "Pay now" link |
| Summary | savings, paid through, outstanding, advance and shares |
| Recent payments | last 5, each with what its status means and the reason if turned down |
| Pay now | shown only when something is owed |

## Every record page: History

Payments, expenses, transfers, investments, exits, rate plans and year-ends have a **History** box: each step that happened (who, when, reason) and, while waiting, who can act now. It shows only steps stored in the record — never a guessed approval.
