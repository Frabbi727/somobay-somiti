# Notification matrix

Who is told what, and how. Every message says **what happened** and **what to do next**, and has an **Open** button to the right page.

How it works (`app/Domain/Notifications/Services`):
- **Bell 🔔** (in-app, both panels): sent only after the action has been saved, in each person's own language (Bangla/English from their account). Deactivated users get nothing. Unread messages show a count on the bell; opening one marks it read; old ones stay in the bell's history.
- **Recipients are chosen by the same rules that show the buttons.** "People who can approve" means exactly the active staff the policy allows to approve *that* item — never "all admins". Someone who entered an item is never asked to approve it.
- **SMS** goes only to members, only for the events listed, through editable templates (**Administration › SMS templates**). Each SMS has a unique key, so a retried action never texts twice. Failed SMS can be sent again from the SMS log.
- **No financial detail goes to anyone who cannot see the record.**
- **The queue worker must be running** (`php artisan queue:work`, kept alive on the server). Bell messages and SMS are both queued jobs: without the worker nothing reaches the bell or the phone, even though the action itself is saved.

## Staff (bell)

| Event | Who is told | Message says |
|---|---|---|
| Payment recorded (desk or member's portal/app) | people who can approve it | member, amount, method → check and approve or reject |
| Payment approved | whoever recorded it | approved and recorded in the books |
| Payment rejected | whoever recorded it | reason; nothing recorded; correct and re-enter if needed |
| Payment reversed | whoever recorded it and whoever approved it | reason; books and dues are back as before |
| Expense / fund transfer recorded | people who can approve it (president only above the expense limit) | number, amount, payee/route → check and decide |
| Expense / transfer approved, rejected | whoever recorded it | outcome (+ reason) |
| Expense / transfer reversed | recorder and approver | reason |
| Investment recorded | people who can approve it | number, amount, institution |
| Investment approved / rejected | recorder | outcome |
| Rate plan submitted | president and secretary (not the drafter) | code, share unit, start month → check the impact preview |
| Rate plan fully approved / sent back | drafter | approved from month X / read the comment and resubmit |
| Year-end prepared | people who can approve it | net profit → review and approve |
| Year-end: one approval done | whoever can give the remaining approval | your approval posts it |
| Year-end posted | preparer and president | dividends can now be paid |
| Exit requested | people who can approve it | member wants to leave → check the settlement |
| Exit approved | requester; and people who can pay it | settlement amount waits to be paid |
| Manual voucher drafted | people who can post it (not the author) | someone else must check and post it |
| Voucher posted | author and last editor | posted |
| Registration submitted / moved to the next step | everyone holding the role of the current step | name, mobile → review, approve, send back or reject |
| A staff account created or its roles changed | president and super admins (not the person who did it) | who changed whose roles |
| Role permissions changed | president and super admins (not the person who did it) | how many permissions changed |
| Items waiting more than N days (daily 09:00) | each person they wait for | how many, how long → open the dashboard (at most once a day) |
| Books self-check found problems (daily 02:00) | super admins and accountants (bell, email, SMS) | number of problems → open the report |
| Backup failed / unhealthy | super admins | backup problem |
| Dues generation finished / failed (run from the screen) | whoever started it | result |

The reminder delay is `SOMITI_APPROVAL_REMINDER_DAYS` (default 3; 0 turns reminders off).

## Members (portal bell and SMS)

| Event | Portal / app | SMS |
|---|---|---|
| Registration sent back | bell: correct and resubmit | ✓ with reason |
| Registration rejected | (sign-in blocked) | ✓ with reason |
| Registration approved / member created | bell: welcome | ✓ welcome |
| Monthly dues generated (if anything is owed) | dues page | ✓ month, amount, due date |
| Payment approved | bell | ✓ receipt with voucher no. and "paid through" |
| Payment rejected | bell + reason under the payment + dashboard alert | ✓ with reason (new) |
| Payment reversed | bell + reason + dashboard alert | ✓ with reason (new) |
| Login code (only if SMS sign-in is switched on) | — | ✓ |

The mobile app's "Notifications" screen shows the member's SMS history (login codes excluded), so new SMS appear there automatically.

## Not sent (by decision)

- No due-date reminder SMS (cost; decided 2026-10-11).
- No email to staff except the books self-check alert and backup reports.
- No SMS to staff except the books self-check alert.
