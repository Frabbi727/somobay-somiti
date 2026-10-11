# Error resolution guide

What each message means and what to do. Messages appear in red at the top right (staff panel), under the field (forms), or in the app. **If a message says "nothing was saved", it is safe to try again after fixing the cause.** If you are unsure whether something was saved, open its list and look before entering it again — never enter money twice "just in case".

## General messages

| Message (short) | Why it happens | What to do |
|---|---|---|
| You do not have permission to do this, or it is no longer waiting for you. Nothing was saved. | Your role cannot do this, your permission was changed, or someone else already acted | Refresh the page. If you should have access, ask the president (Role permissions) |
| Someone else was changing the same record at that moment, so nothing was saved. (Reference …) | Two people worked on the same record at the same second | Refresh, check the current status, try again only if still needed |
| The books refused this change, so nothing was saved. Reference … | A database safety rule stopped something the screens should have prevented | Do not retry. Tell the administrator the reference; they can find the details in the log |
| Something went wrong (panel pop-up) | Unexpected server problem | Refresh and **check whether your change was saved** before trying again. If it repeats, tell the administrator the time |
| The page has expired | You were away for a while | Refresh and try again; nothing was saved |
| Too many attempts | Sign-in or SMS code tried too often | Wait a minute |
| Error page with "Reference for support: XXXXXXXX" | A full page failed | Give the reference to the administrator |

**For the administrator:** every request has an 8-character reference (no 0/O/1/I). Search the log for it (`storage/logs/laravel.log`, field `error_ref`) to see the technical details.

## Approvals

| Message | Why | What to do |
|---|---|---|
| This payment is already approved/rejected — someone has already dealt with it. | Another checker acted first | Refresh; nothing more to do |
| Expense / Transfer / Investment … is no longer waiting for approval | Same | Refresh |
| You approved this payment (expense, transfer), so someone else must reverse it. | Second-person rule | Ask another committee member who may reverse |
| You prepared or last edited this voucher, so someone else must check and post it. | Second-person rule | Ask another accountant or the president |
| You prepared this year-end, so another accountant must approve it. | Second-person rule | Ask another accountant; or the president prepares and the accountant approves |
| This step is waiting for the :role, not you. | Registration order | Wait for your turn; you get a bell message |
| You already decided on this registration/plan | One decision per person per submission | Another person decides the next step |
| Refunds of ৳… or more must be made by the president | Large refund | Ask the president |
| Only the president or the secretary can approve rate plans | Fixed rule | — |
| Waiting — but nobody can approve this right now (History box) | No active user has the needed permission (or the only one entered it) | President: check Role permissions; super admin: check staff accounts are active |

## Money and books

| Message | Why | What to do |
|---|---|---|
| :account holds only :balance, so :amount cannot be paid from it | Not enough money recorded in that account | Check the amount; record the deposit/transfer first, or pay from another account |
| The month :month is locked, so nothing can be posted in it | Month closed by the accountant | Use a date in an open month, or ask the accountant to unlock it |
| There is no fiscal year for :date yet | Next fiscal year not opened | Accountant: Accounting › Fiscal years › Open |
| Fiscal year :code is closed | Year finished | Use a date in the current year |
| Debits and credits must be equal | Voucher not balanced | Fix the lines |
| Account :code is kept by its register | Manual vouchers cannot touch advances, dividends, exits, investments | Use the matching screen (payments, exits, investments, year-end) |
| Transaction ID :trx has already been recorded | Same bKash/Nagad TrxID used before | Check whether this payment was already entered |
| This form was already submitted with different details | Double submission with changed data | Reload the form and enter it once |
| This would make the member’s advance negative | Advance already used elsewhere | Ask the accountant to check the member's advance history |

## Members, shares, rates

| Message | Why | What to do |
|---|---|---|
| Dues already exist up to :latest, so shares cannot change from :month | Months with dues never change | Choose a later month |
| Dues already exist up to :latest, so a plan cannot start in :month | Same for rate plans | Choose a month after :latest |
| Retroactive rate corrections are not available yet | Switched off by decision | Start the new rate from a coming month |
| There is no approved rate plan for :month yet | No prices for that month | Approve a rate plan first |
| Mobile / NID already belongs to another member | Duplicate | Check the existing member |
| Nominee shares must add up to 100% | — | Fix the percentages |

## Exits

| Message | Why | What to do |
|---|---|---|
| The exit can be approved after :month has ended | Exit month not over | Wait |
| The member has payments awaiting approval | Pending payments | Approve or reject them first |
| The member still owes :amount more than they hold | Shortfall | Collect it first |
| This member has a share change planned for after :month | A future share change exists | Check the share history first |

## Year-end

| Message | Why | What to do |
|---|---|---|
| Lock every month except the last before closing the year | Months still open | Lock them (Fiscal years) |
| :count items dated in this year are still awaiting approval | Pending payments/expenses/transfers/investments | Approve or reject them first |
| The books changed since this year-end was prepared | Something was posted after preparing | Prepare it again, then approve |

## SMS

| Message | Why | What to do |
|---|---|---|
| Only a failed message can be sent again | Already sent or queued | Refresh |
| :placeholder is not available for this message | Typo in a template | Use only the listed placeholders |

## Member app / portal

| Message | What to do |
|---|---|
| Mobile number or password is not correct | Check both; ask the secretary to reset the portal password |
| Your registration was not approved | Contact the office |
| Something went wrong on our side… reference: XXXXXXXX | Check your payments list before paying again; give the reference to the office |
