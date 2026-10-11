# Roles and permissions

This describes who can do what in Somobay Somiti **as the code enforces it** (checked October 2026). Every rule here is checked on the server, inside the action that does the work, not just by hiding a button.

## How access works, in one paragraph

Each staff person has one or more **roles**. Each role holds **permissions** (tick-boxes). The president can change which role holds which permission on **Administration › Role permissions**; every change is logged and the president and super admins get a bell message. Some rules are **never** tick-boxes and stay fixed in code: the second-person rules, who signs a rate plan and a year-end, the president's approval for large amounts, user management, and the read-only auditor.

There is **one somiti per installation**. There is no multi-organisation data split, so "one somiti cannot see another's data" is guaranteed by separate installations.

## The roles

| Role | Bangla | What they are for |
|---|---|---|
| Super admin | সুপার এডমিন | Keeps the system running: staff accounts, society profile, SMS texts, backups. **Never** approves, reverses or posts money and cannot change their own roles. |
| President | সভাপতি | Final say: approves rate plans (with the secretary), large expenses and refunds, investments, exits, the year-end (with an accountant); controls role permissions. |
| Secretary | সম্পাদক | Members, registrations, meetings, resolutions, exit requests; co-approves rate plans; edits SMS texts. |
| Cashier | ক্যাশিয়ার | Records money received and spent (maker). Cannot approve. |
| Accountant | হিসাবরক্ষক | Checks and approves what the cashier records (checker), vouchers, reconciliation, dues, year-end preparation and approval, reports. |
| Auditor | নিরীক্ষক | Sees everything including the audit log; can run the books self-check; can never change anything. |
| Member | সদস্য | Uses only the member portal / app: own dues, payments, statement; reports own bKash/Nagad payments. |

A person may hold several roles. The rules below still apply person by person (for example, the person who recorded a payment never approves it, whatever roles they hold).

## Rules fixed in code (cannot be changed on the permissions page)

| Rule | Where it is enforced |
|---|---|
| Nobody approves an item they entered (payments, expenses, transfers, investments, exits, rate plans) | policies + database CHECKs |
| Nobody reverses an item they approved (payments, expenses, transfers) | policies, actions, `*_reverser_differs` CHECKs |
| A manual voucher is posted by someone other than whoever prepared or last edited it | `JournalDraftPolicy::post`, `PostJournalDraft`, `journal_drafts_poster_differs` trigger |
| A rate plan needs the president **and** the secretary, two different people, neither the drafter | `RatePlanPolicy::approve` |
| A year-end needs the president and an accountant, two different people; the accountant is not the one who prepared it | `YearEndPolicy`, `year_ends_two_approvers` and `year_ends_preparer_not_accountant_approver` CHECKs |
| Expenses at or above `SOMITI_EXPENSE_PRESIDENT_THRESHOLD_POISHA` (default ৳10,000) are approved by the president | `ExpensePolicy::approve` |
| Advance refunds at or above `SOMITI_REFUND_PRESIDENT_THRESHOLD_POISHA` (default ৳5,000) are made by the president | `RefundAdvance` |
| Registrations are decided by the role at the current step of the approval order (default secretary → president); nobody decides their own or twice | `MemberApplicationPolicy::decide` |
| The last registration approver's role keeps "add members" | `UpdateRegistrationApprovalChain`, `UpdateRolePermissions` |
| The auditor is read-only | `Permission::isLockedFor` |
| The super admin never holds an approve/reverse/post permission | `Permission::isChecker`, `isLockedFor` |
| Only the super admin manages staff accounts; nobody changes their own roles; one active super admin always remains | `UserPolicy`, `StaffUserRules` |
| Only the president changes role permissions | `manageRolePermissions` gate |
| Only the super admin runs backups and restores | `manageBackups` gate |

## Default role → permission matrix

✓ = given by default (the president can change any box that is not locked; "—" = locked, never allowed).

| Permission | Super admin | President | Secretary | Cashier | Accountant | Auditor |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| See members | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| See collections (payments, dues, expenses, transfers) | ✓ | ✓ | | ✓ | ✓ | ✓ |
| See accounting | ✓ | ✓ | | | ✓ | ✓ |
| See governance (meetings, resolutions, exits) | ✓ | ✓ | ✓ | | ✓ | ✓ |
| See rate plans | ✓ | ✓ | ✓ | | ✓ | ✓ |
| See messaging (SMS) | ✓ | | ✓ | | | ✓ |
| See financial reports | ✓ | ✓ | | | ✓ | ✓ |
| See member reports | ✓ | ✓ | ✓ | | ✓ | ✓ |
| See cash reports | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| See audit log | ✓ | ✓ | | | | ✓ |
| Add / edit / deactivate members, set portal passwords | | ✓ | ✓ | | | — |
| Meetings, resolutions, request exits | | ✓ | ✓ | | | — |
| Record payments, expenses, transfers | | ✓ | | ✓ | ✓ | — |
| Approve / reverse payments, expenses, transfers | — | ✓ | | | ✓ | — |
| Generate dues, waive late fees, refund advance | — | ✓ | | | ✓ | — |
| Chart of accounts, open fiscal years | ✓ | | | | ✓ | — |
| Manual vouchers (prepare/post), reverse vouchers | — | ✓ | | | ✓ | — |
| Lock periods, import and match statements | | ✓ | | | ✓ | — |
| Run books self-check | ✓ | | | | ✓ | ✓ |
| Record investments | | ✓ | ✓ | | ✓ | — |
| Approve investments, write them down | — | ✓ | | | | — |
| Record investment income, close investments | — | ✓ | | | ✓ | — |
| Prepare year-end, settle dividends | — | ✓ | | | ✓ | — |
| Approve exits | — | ✓ | | | | — |
| Pay out exits | — | ✓ | | | ✓ | — |
| Draft rate plans | ✓ | | ✓ | | ✓ | — |
| Cancel rate plans | — | ✓ | | | | — |
| Edit SMS texts, resend failed SMS | ✓ | | ✓ | | | — |
| Society profile, registration approval order | ✓ | ✓ | | | | — |

Source: `app/Enums/Permission.php` (`defaultRoles()`), `app/Enums/Area.php`.

## Panels

| Panel | Address | Who |
|---|---|---|
| Staff | `/admin` | active users with any staff role |
| Member portal | `/portal` | active or inactive members (not exited), and people completing their registration |
| Member API (mobile app) | `/api/v1/...` | Sanctum tokens; every call is limited to the member's own records |

## Findings from the audit (October 2026)

Fixed on branch `ux-roles-approvals`:
- The super admin could give themselves the president role → now nobody changes their own roles, and role changes alert the president.
- The president could tick approve/reverse permissions for the super admin → now locked.
- Manual vouchers had no second person → now required.
- The approver of a payment, expense or transfer could also reverse it → now refused.
- The accountant who prepared a year-end could also sign it → now refused.
- Registration decisions skipped the policy's staff check → now go through the policy.
- Removing "add members" from the last registration approver would leave registrations stuck → now refused.

Still open (decisions for the committee, see `known-gaps-and-risks.md`): single-person advance refunds below the threshold, late-fee waivers, dividend settlement, exit payout, investment income/closing, resolution outcomes; two report pages use a different area than `Area.php` describes (Cash book, Member statement).
