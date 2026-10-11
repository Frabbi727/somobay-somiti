# User manual (English)

Short, step-by-step help for everyday work. The Bangla version is `user-manual-bn.md`.

## 1. Before you start

- **Sign in:** staff at `/admin` with your email and password; members at `/portal` or in the app with mobile and password.
- **Language:** change it from your account menu (top right).
- **Your home page** shows: buttons for your everyday jobs, **Waiting for you** (what needs your action), **What you entered** (your entries and who has them now), and **The somiti today**.
- **The bell 🔔** (top right) has messages about your work. Each one says what happened and what to do; press **Open** to go to the right page.
- **Every save asks you to confirm.** For approvals and other money actions you type a word or number the box shows you. This is on purpose.
- **Two people for money.** The person who enters something never approves it, and the person who approves never reverses it. If a button is missing, it is usually this rule.
- **If something goes wrong**, read the red message: it says whether anything was saved and what to do. If it shows a reference like `K7Q2M9XA`, give it to your administrator. **Never enter money twice "just in case"** — open the list and check first.

## 2. Cashier

### Record a payment received
1. Press **Collect payment** on the home page.
2. Choose the member. You see their open dues and advance.
3. Enter the amount, method (cash, bank, bKash, Nagad), the TrxID for bKash/Nagad, and the date.
4. Check the preview ("what this payment settles") and press **Record**, then confirm.
5. The payment is now **Awaiting approval**. Nothing is in the books yet. The accountant gets a bell message.
6. You get a bell message when it is approved or rejected. Track it under **What you entered** on your home page.

### Record an expense or a fund transfer
**Collections › Expenses › New expense** (or the home-page button): category, paid from, amount, date, what it was for, bill photo if any → **Create** → confirm. It waits for approval like a payment. Large expenses go to the president.

### If your payment was rejected
Open the bell message to read the reason. Nothing was recorded. Correct the details and record it again if needed.

### Withdraw your own entry
While it still waits for approval, open it and press **Cancel** (shown as "Withdrawn").

## 3. Accountant

### Approve or reject
1. On the home page, **Waiting for you** shows how many payments, expenses, transfers and vouchers wait for you. Press a tile.
2. Open an item, check it (amount, TrxID, bill), then **Approve** (type the confirmation) or **Reject** with a reason.
3. Approving records it in the books at once and sends the member a receipt SMS. Rejecting records nothing and tells the cashier and the member why.
4. You cannot approve what you entered yourself.

### Reverse an approved entry
Open it and press **Reverse**, give a reason. Someone **other than the person who approved it** must do this. A mirror voucher is posted; nothing is deleted.

### Manual voucher
**Accounting › Voucher drafts › New** → enter the lines (they may be unbalanced while drafting) → save. **Another accountant or the president checks and posts it**; you get a bell message when it is posted.

### Monthly work
- Dues are created automatically on the 1st; to run it yourself use **Generate dues**.
- Import bank statements (**Bank & wallet statements › Import**) and match the lines.
- Lock finished months (**Fiscal years**).
- Watch **Needs attention**: if the books self-check finds problems, open the report.

### Year-end
1. Lock every month except June.
2. **Year End › Closing wizard**: check the figures and prepare.
3. The president approves, then **an accountant who did not prepare it** approves. The year is then posted, closed and the next one opened.
4. Pay dividends in cash or credit them to savings.

## 4. President

- **Waiting for you** shows everything that needs you: payments, large expenses, investments, rate plans, year-ends, exits, registrations at your step.
- **Rate plans:** open the plan, read the impact preview (how many members and how much it changes), then **Approve** or **Send back** with a comment. The secretary must approve too.
- **Exits:** approve after the exit month has ended; the settlement is calculated for you.
- **Role permissions** (Administration): tick which role may do what. Some rules cannot be changed (listed on the page). You get a bell message whenever roles or permissions change.
- If an item's **History** says "nobody can approve this right now", give the right role the permission or ask the super admin to activate an account.

## 5. Secretary

### Add a member yourself
**Members › New**: details, at least one nominee with NID, shares and the start month → confirm. The registration fee due is created.

### Let a member register themselves
1. **Members › Registrations › Invite member**: enter their mobile and a password; give them the password.
2. They fill the form in the app or portal and submit.
3. When it reaches your step you get a bell message. Open it and **Approve**, **Send back** (they can correct and resubmit) or **Reject**.
4. The president gives the final approval and confirms the shares and start month.

### Other work
Meetings and resolutions; exit requests (**Exits › New**); SMS texts (**Administration › SMS templates**); **SMS log** — press **Send again** on a failed message after the SMS balance is topped up.

## 6. Auditor

You can open everything and the **Audit log**, and run the books self-check, but cannot change anything.

## 7. Super admin

Staff accounts (**Administration › Staff users**): create, edit, deactivate. You cannot change your own roles (another super admin must) and you never approve money. Society profile, backups (**Backups**: back up now, download, restore — a safety backup is taken first).

## 8. Members (portal and app)

- **Home:** your savings, the month you are paid through, what you owe, your advance and shares, and alerts about your payments.
- **Pay:** pay by bKash/Nagad as usual, then **Pay by bKash/Nagad** (portal menu) → enter the amount and TrxID → submit. It shows **"being checked by the office"** until approved; you do not need to pay again. You get a receipt SMS when approved.
- **If a payment was not accepted** you get an SMS and see the reason on your home page and under **Receipts**. Check the details and pay again, or contact the office.
- **Receipts / Statement / Dividends:** download receipts and your statement.
- **Registering yourself:** fill every section, add at least one nominee with NID, submit. Follow each approval step under **Registration status**. If it is sent back, read the reason, correct and submit again.

## 9. What the status words mean

| Status | Meaning |
|---|---|
| Awaiting approval / অনুমোদনের অপেক্ষায় | Entered; a second person must check it. Not in the books yet |
| Approved / অনুমোদিত | Checked and recorded in the books |
| Rejected / প্রত্যাখ্যাত | Turned down before approval, with a reason. Nothing recorded |
| Withdrawn / প্রত্যাহার করা | Taken back by the person who entered it |
| Reversed / রিভার্স করা | Approved earlier, then undone with a reason. Both entries stay in the books |
| Sent back for correction / সংশোধনের জন্য ফেরত | Fix it and submit again |
