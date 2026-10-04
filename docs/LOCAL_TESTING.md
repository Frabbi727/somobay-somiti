# Testing Somiti locally

A demo somiti with one login per role, two years of history and work waiting in every queue, so each
feature can be tried by the person who would really do it.

## 1. Start everything

```bash
# Database: your local PostgreSQL (Homebrew) — or `docker compose --profile db up -d` (see docker-compose.yml)
docker compose up -d                       # Mailpit: every email the app sends → http://localhost:8025

php artisan somiti:demo --fresh            # WIPES the local database and builds the demo (≈ 6 s)

php artisan serve --port=8002              # the app → http://127.0.0.1:8002   (8000/8001 are another project)
php artisan queue:work                     # in a 2nd terminal: sends SMS (to the log) and emails (to Mailpit)
php artisan schedule:work                  # in a 3rd terminal: dues on the 1st, late fees daily, integrity 02:00, backups
```

`APP_URL` in `.env` must match the port you use. SMS are written to `storage/logs/laravel.log` (driver `log`).
Two-factor login is switched off locally (`SOMITI_REQUIRE_MFA=false`); in production it is on.

To start again at any time: `php artisan somiti:demo --fresh`.

## 2. Logins (password for all: `password`)

**Staff — http://127.0.0.1:8002/admin**

| Email | Role | Typical work |
|---|---|---|
| admin@somiti.test | Super admin | Users, SMS templates, integrity, backups |
| president@somiti.test | President | Approves rate plans, investments, year-end, exits, large expenses |
| secretary@somiti.test | Secretary | Members, share changes, meetings, resolutions, exit requests |
| cashier@somiti.test | Cashier | Records collections, expenses, bank deposits |
| accountant@somiti.test | Accountant | Approves payments/expenses/transfers, vouchers, reconciliation, year-end |
| accountant2@somiti.test | Accountant | A second checker (one accountant cannot approve their own entries) |
| auditor@somiti.test | Auditor | Read-only everywhere, all reports |

**Members — http://127.0.0.1:8002/portal** (mobile + `password`). SMS codes are off locally
(`SOMITI_PORTAL_OTP=false`); for a new member, the secretary or president opens the member and uses
**Set portal password**. With codes on, the code is written to `storage/logs/laravel.log`.

| No. | Member | Mobile | Story |
|---|---|---|---|
| M-0001 | রহিম উদ্দিন · Rahim Uddin | 01711000001 | 2 shares, pays every month — October payment waiting for approval |
| M-0002 | করিম মিয়া · Karim Mia | 01711000002 | 1 share, pays by bKash — October bKash payment waiting |
| M-0003 | সালমা বেগম · Salma Begum | 01711000003 | 3 shares, paid ৳12,000 ahead in July 2025 (advance) |
| M-0004 | জামাল হোসেন · Jamal Hossain | 01711000004 | 1 share, stopped paying after July 2026 — defaulter with late fees |
| M-0005 | ফারুক আহমেদ · Faruk Ahmed | 01711000006 | 1 share, fully paid — good for a voluntary exit |
| M-0006 | নাসরিন আক্তার · Nasrin Akter | 01711000005 | joined January 2026, 2 nominees (60/40) — good for a "deceased" exit |

**The story so far:** the society started in July 2025 at ৳500 a share (+৳20 service charge, ৳100 registration
fee, ৳20 late fee). A December committee resolution raised the share unit to ৳600 from January 2026.
Fiscal year 2025-26 is complete; July–May are locked and June is open, so its year-end can be run now.

## 2a. What each role sees

Each login shows only its own part of the panel (the rules live in `app/Enums/Area.php`), and the
dashboard opens with **Waiting for you** — the queues that person can act on — plus the somiti's
headline figures (money figures only for roles that handle money).

| Role | Menus |
|---|---|
| Cashier | Members (look-up), Collections (collect, payments, advance balances), Dues, Expenses, Fund transfers; reports: member statement, defaulters, collection summary, cash book |
| Secretary | Members, Meetings, Resolutions, Exits, Settings › Rate plans & SMS; reports: member statement, share register, dividend register, defaulters, collection summary |
| Accountant | Everything financial: collections, dues, vouchers, chart of accounts, fiscal years, expenses, transfers, statement reconciliation, investments, year-end, exits, rate plans, all reports, integrity |
| President | Same as the accountant (approves rate plans, investments, year-end, exits, large expenses) |
| Auditor | Everything, read-only (no action buttons) |
| Super admin | Everything read-only, plus Settings › Users and SMS templates |

Pages outside a role's area answer "403 forbidden" even if the address is typed in directly.

## 3. Try every feature, step by step

Use the language switch (top right) for বাংলা / English. Every write asks for a confirmation;
financial ones (T3) make you type a number such as the member no. or voucher no.

### Users and roles — `admin@somiti.test`
1. **Settings › Users** → *New staff user*: create someone with any role (e.g. a second cashier).
2. Edit a user's roles; **Deactivate** them (type their email) → they can no longer sign in; reactivate.
3. **Settings › SMS templates**: edit a message.

### Collections — `cashier@somiti.test` then `accountant@somiti.test`
1. Cashier: **Collections › Collect payment** for Jamal (M-0004) → it waits as *pending*.
2. Accountant: **Collections › Payments** → approve Rahim's and Karim's waiting payments and Jamal's
   (type the member no.). Download the receipt PDF.
3. Accountant: **Reverse** a payment (with a reason) → the dues reopen, a mirror voucher is posted.
4. Note: the cashier cannot approve, and nobody can approve a payment they recorded.

### Members and shares — `secretary@somiti.test`
1. **Members › New member** with nominees adding up to 100% → registration-fee due created.
2. **Change shares** for a member from next month → registration fee for the new shares.
3. **Deactivate / reactivate** a member.

### Dues — `accountant@somiti.test`
1. **Dues**: see Jamal's open dues and late fees; **Waive** a late fee (type the confirmation it asks for).
2. **Dues › Generate dues**: preview a month (already generated months show nothing new).

### Rate change — `accountant@somiti.test` → `president@somiti.test` + `secretary@somiti.test`
1. Accountant: **Settings › Rate plans › New** (e.g. ৳650 from next month) → **Submit** (impact preview).
2. President and secretary each **Approve** (type the plan code). Approved plans can no longer change.

### Expenses, transfers, statements — `cashier@` then `accountant@` (and `president@`)
1. Accountant: **Accounting › Expenses** → approve the waiting October rent (type its E- number).
2. Cashier: record an expense of ৳10,000 or more, paid from the bank → only the president can approve it.
3. Accountant: **Fund transfers** → approve the waiting bank deposit.
4. Accountant: **Statement reconciliation › Import statement** → Bank → upload
   `storage/app/private/demo/bank-statement.csv` (written by `somiti:demo`). The deposits and the FDR
   match automatically; the ৳115 bank charge is left unmatched → record it as an expense
   (5104, paid from bank) and **Match** it, or **Ignore** it with a reason.

### Vouchers and reports — `accountant@`, then `auditor@`
1. **Accounting › Vouchers**: open any voucher; manual vouchers via **Journal drafts** (post = type).
2. **Reports**: trial balance, ledgers, income statement, balance sheet, receipts & payments, cash book,
   member statement, defaulters (Jamal), share register, investment register, dividend register —
   each as PDF and Excel. The auditor sees everything but cannot change anything.

### Investments — `accountant@` → `president@`
1. **Investments › Record investment** (e.g. a savings certificate from the bank) → president approves.
2. On the existing FDR: **Record profit**, **Write down** (president), **Close / mature**.

### Governance — `secretary@somiti.test`
1. **Governance › Meetings**: the AGM is scheduled for today 6 pm → **Record attendance** (pick at least
   3 of the 6 members — quorum is one third, rounded up) → **Mark as held**.
2. In its resolutions, **Record vote** on R-0003 (type R-0003) → passed.
3. Optional: set `SOMITI_REQUIRE_RESOLUTION_FOR=rate_plan,investment,year_end,exit` in `.env` to make
   resolutions compulsory for those approvals.

### Year-end 2025-26 — `accountant@` → `president@` + `accountant2@`
1. Accountant: **Year End › Closing wizard** → year 2025-26, link the AGM resolution, check the preview
   (net profit, reserve 15%, development fund 3%, dividend by share-months) → **Prepare**.
2. President **Approve** (type 2025-26), then an accountant **Approve** → closing vouchers posted,
   2025-26 closed, dividends declared.
3. In the year-end's dividend register: **Pay or credit** one member, then **Credit all unpaid to savings**.

### Member exit — `secretary@` → `president@` → `accountant@`
1. Secretary: **Exits › Request exit** for Faruk (M-0005), last month = last month, reason "left voluntarily".
   The page shows a live settlement preview.
2. President: **Approve settlement** (type M-0005) → October's prepaid dues are released, savings settled,
   Faruk becomes *exited*.
3. Accountant: **Pay out** from cash.
4. Try Nasrin (M-0006) as **Deceased** → the payout is split 60/40 between her nominees.

### Member portal — `01711000003` / `password` (Salma)
The portal looks and works like the staff panel: menu on the left (a drawer on phones), dark mode, language
switch, tables with filters and paging. Pages:
**Dashboard** (savings, advance, paid-through, outstanding, recent payments, *Pay now*), **Dues**,
**Payments & receipts** (receipt PDF once approved), **Pay by bKash/Nagad** (TrxID + screenshot → pending
for the accountant), **Statement** (date range + PDF), **Dividends**, **Profile** (nominees, change password).
Staff logins cannot open `/portal`, and member logins cannot open `/admin`.

### Integrity, alerts and email — `admin@somiti.test`
1. **Reports › Integrity report** → **Run checks now** → 14 checks, all clear.
2. Failure alerts go to super admins and accountants (panel, email in Mailpit, SMS in the log).

## 4. Undo / restore

* Rebuild the demo: `php artisan somiti:demo --fresh`.
* The database as it was before the demo: `pg_restore --clean -d somiti storage/backups/somiti-before-demo-*.dump`.
