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
- **Rules drafts and publishing:** see "Society rules: view and publish a new version" in section 5; you can do this too.
- **Rate plans:** open the plan, read the impact preview (how many members and how much it changes), then **Approve** or **Send back** with a comment. The secretary must approve too.
- **Exits:** approve after the exit month has ended; the settlement is calculated for you.
- **Role permissions** (Administration): tick which role may do what. Some rules cannot be changed (listed on the page). You get a bell message whenever roles or permissions change.
- If an item's **History** says "nobody can approve this right now", give the right role the permission or ask the super admin to activate an account.

## 5. Secretary

### Add a member yourself
**Members › New**: laid out like the paper form. The registration fee due is created.

1. **Personal details:** name, father's / husband's name, NID, date of birth, **Gender** (Male, Female, Other) and **Passport no.** (optional; 6–20 capital letters or digits).
2. **Addresses:** **Present address** and **Permanent address**, each as Division › District › Upazila › Union, then **House / road / village**.
   - Type in any list to search, in Bangla or English; the first few letters of a name are enough (for example "dhanm").
   - Changing a higher list clears the ones below it; choose them again. Each list only offers places under the one above, so two upazilas with the same name never get mixed up.
   - If an upazila has no unions, leave Union empty.
   - If both addresses are the same, tick **Permanent address is the same as the present address**.
3. **Shares and the start month.**
4. **Documents received:** tick what you have in hand: National ID (NID), Passport, Nominee's National ID (NID), Nominee's photo. This is an office record; you can change it later from the member's edit page.
5. **Nominees:** at least one, with NID. For each: name, relation, mobile, share, **Gender** and their own present and permanent addresses (same search lists and the same "same as" tick). Shares must total 100%.
6. **Rules:** if the member has read the rules and signed the paper form, tick "The member has read and agreed to the somiti rules (version …)". **The member cannot be created without this tick.** The accepted version is kept on the member's profile.
7. Confirm.

When a new member is created, gender, both addresses (upazila, a union where the upazila has unions, and the house / road / village line) and the same details for each nominee are required. An existing member can still be edited and saved with these left empty.

### Let a member register themselves
1. **Members › Registrations › Invite member**: enter their mobile and a password; give them the password.
2. They fill the form in the app or portal and submit.
3. When it reaches your step you get a bell message. Open it to see the personal details (gender and passport no. included), both addresses, each nominee's details and addresses, and the version of the rules the member agreed to. Then **Approve**, **Send back** (they can correct and resubmit) or **Reject**.
   - **Record documents received:** tick the documents the member has handed in (NID, passport, nominee's NID, nominee's photo). Only people who can create members (secretary and president) see this button, and only while the registration is open (invited, submitted or sent back). After approval, change the list from the member's edit page.
4. The president gives the final approval and confirms the shares and start month.

### Society rules: view and publish a new version
**Administration › Somiti rules**:

- **View:** super admin, auditor, president, secretary, cashier and accountant can see every version, the 24 articles, the declaration and how many members have agreed.
- **New draft:** only the president and secretary. Press **New draft from current**; a draft is created as a copy of the published rules. Fill in the rules and declaration in both Bangla and English, then save. A draft can be edited as often as needed.
- **Publish:** open the draft and press **Publish**, then type the new version number the box shows. A published version can never be edited or deleted; to correct it, make a new draft and publish again.
- **Effect:** every member, including existing ones, must agree again (see section 8). Someone who was in the middle of registering sees the new text and agrees again when submitting.

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
- **Registering yourself:** the steps are **Personal**, **Contact**, **Nominees**, **Shares**, **Review**, **Rules**.
  - **Personal:** name, NID, date of birth, **Gender** and (optional) **Passport no.** (6–20 capital letters or digits).
  - **Contact:** **Present address** and **Permanent address**: choose Division › District › Upazila › Union and type the **House / road / village**. Type the first letters in Bangla or English to search a list. Changing a higher list clears the ones below it. If both are the same, tick **Permanent address is the same as the present address**. If the upazila has no unions, leave Union empty.
  - **Nominees:** at least one with NID; for each, gender and their own present and permanent addresses (same lists and tick). Shares must total 100%.
  - **Review:** check everything once more.
  - **Rules** (last step): read the 24 articles and the declaration, tick "I have read and agree to the somiti's rules and declaration", then press **Submit registration**. **You cannot submit without the tick.** Follow each approval step under **Registration status**. If it is sent back, read the reason, correct and submit again.
- **When new rules are published:** after you sign in, the **Rules and declaration** page comes first (in the app, the same message before anything else opens). This applies to existing members too. Read the rules, tick the box and press **Agree and continue**; nothing else opens until you do (except signing out).
- **Profile:** the row **Rules I accepted (version …, date)** shows which version you agreed to and when. **Read the rules** lets you read them again.

## 9. What the status words mean

| Status | Meaning |
|---|---|
| Awaiting approval / অনুমোদনের অপেক্ষায় | Entered; a second person must check it. Not in the books yet |
| Approved / অনুমোদিত | Checked and recorded in the books |
| Rejected / প্রত্যাখ্যাত | Turned down before approval, with a reason. Nothing recorded |
| Withdrawn / প্রত্যাহার করা | Taken back by the person who entered it |
| Reversed / রিভার্স করা | Approved earlier, then undone with a reason. Both entries stay in the books |
| Sent back for correction / সংশোধনের জন্য ফেরত | Fix it and submit again |
