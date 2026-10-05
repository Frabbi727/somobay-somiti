# Go-live import: members and opening balances — design

Date: 2026-10-05 · Status: awaiting review

## 1. Purpose

A society that already runs on paper or spreadsheets needs every existing member and its money
position in the app in one controlled step, so the books start balanced, each member's portal shows
the right figures, and the normal monthly cycle (dues generation, payments, advance engine) takes over
from the go-live month.

**Success:** after one post, the trial balance equals the society's real position; every member's
savings (2101) and advance (2111) sub-ledgers equal the sheet; open arrears appear in Defaulters and the
portal; the next dues generation for the go-live month runs normally and applies existing advances.

## 2. Decisions (agreed)

| Topic | Decision |
|-------|----------|
| History | Balances only. Statements start at go-live; older history stays on paper. |
| Other side of the journal | The society's own opening balances are imported too; the difference goes to Accumulated Surplus (3901). |
| Investments | Imported per investment (fourth sheet) so the register ties to GL 13xx — found in self-review. |
| Arrears | Per member: `arrears_deposit` and `arrears_fees`, each one *opening* due dated the month before go-live. |
| Run mode | Once, all at once. Upload/fix/re-upload as often as needed; one T3 post; then closed for good. |
| Member numbers | Kept from the sheet (`M-####`); the number sequence continues after the highest. |
| Mistakes after posting | No discard (it would break §3.6 immutability and §7.4 no-hard-delete). Rehearse on staging; correct production with the normal tools (edit member, change shares, correcting JV). |
| Welcome SMS | Not sent on import. |

## 3. The workbook

A downloadable `.xlsx` template (headers in bn + en) with four sheets. Money cells accept English or
Bangla digits with up to 2 decimals and are parsed with the existing `Money` parser — never as floats.
Blank money cells mean 0.

### 3.1 Members (one row per member)

| Column | Rule |
|--------|------|
| `member_no` | `M-####`, unique in the sheet |
| `name_bn`, `name_en` | both required |
| `mobile`, `nid` | `MemberRules` (BD mobile, NID format), unique |
| `joined_on` | date; its month ≤ the month before go-live |
| `shares` | integer ≥ 1, within the plan's share limits |
| `savings` | ≥ 0 → Cr 2101 (member) |
| `advance` | ≥ 0 → Cr 2111 (member) + advance ledger Opening entry |
| `arrears_deposit` | ≥ 0 → opening Deposit due (paid later → 2101) |
| `arrears_fees` | ≥ 0 → opening Late-fee due (paid later → 4121) |
| (rule) | `advance` and any arrears may not both be > 0 on one row (net them first) |
| (mobile) | a 10-digit number starting with 1 (Excel dropped the leading 0) is read as 0 + number |
| `nominee1_name`, `nominee1_relation`, `nominee1_percent`, `nominee2_*` | optional; when given, percentages total 100 (stored as `share_bps`) |

### 3.2 Society balances (one row per account)

`account_code`, `amount`. Allowed: 1101, 1111, 1121, 1122 (debit); 2211, 3101, 3201–3203 (credit).
Each code at most once, amount ≥ 0. Member-level accounts (2101, 2111, 2201, 2301, 1201),
investment accounts (13xx) and 3901 are refused — they come from the member rows, the Investments
sheet or the balancing line.

### 3.3 Investments (one row per open investment)

`type` (the existing `InvestmentType`, which fixes the 13xx account), `institution`, `instrument_no`,
`principal` (> 0, the current carrying amount), `invested_on` (before go-live), `matures_on`,
`expected_rate_percent` (stored as bps), `funded_from` (cash/bank/bkash/nagad). Each row becomes an
**active** register entry whose principal is posted as Dr 13xx in the opening journal, so the register
ties to the GL from day one and profit, impairment and closure work normally. No s.33 limit warnings
and no approval step (they predate the app).

### 3.4 Instructions

A filled-in example and these rules, in Bangla and English.

## 4. Flow

```
Download template → fill → Upload ──▶ Parse + validate ──▶ Draft import (preview)
                                  ▲            │ errors listed per sheet/row/column
                                  └── fix ─────┘
Draft (no errors) ──T3 "Post opening balances"──▶ PostOpeningImport (one transaction) ──▶ Posted (read-only)
```

### 4.1 Draft

`UploadOpeningImport` stores the file (private disk), parses it, validates every row and saves one
`opening_imports` row in status **Draft**: go-live month, file name, SHA-256, the parsed payload
(JSONB), the error list (JSONB) and the summary (member count, Σ savings, Σ advance, Σ arrears, the
opening trial balance lines including the 3901 balancing figure). A new upload replaces the draft (a
draft has no financial effect). Only one import may ever reach **Posted**.

### 4.2 Preconditions for posting (checked inside the transaction)

- The draft has no errors and the file on disk still matches the stored SHA-256.
- No members, journal entries, payments or dues exist yet.
- An approved rate plan covers the go-live month.
- The go-live month's period is in an open fiscal year.

### 4.3 `PostOpeningImport` (T3, `DB::transaction(attempts: 3)`, import row locked)

1. **Members** created with the sheet's numbers (bypassing `nextval`), then
   `setval('member_no_seq', max)`; nominees via `NomineeWriter`; portal accounts via `PortalAccounts`.
   `MemberJoined` is not fired (no welcome SMS).
2. **Share lots** from the `joined_on` month, **without** a registration-fee due; share-change history
   and `member_share_snapshots` written as for a normal join. (A `ShareChanger` option to skip the fee,
   used only by the import.)
3. **Investments:** one active `Investment` per row, numbered by the normal sequence, with its register
   entry linked to the opening journal (no approval PV).
4. **Advance:** one `AdvanceLedgerEntry` of a new kind `Opening` per member with advance > 0.
5. **Arrears:** for each non-zero column, one due with `opening = true`, month = go-live − 1, type
   Deposit or LateFee, `rate_plan_id` = the go-live plan, status open. Allocation already settles older
   months first, so these are paid before go-live dues.
6. **Opening journal** (JV, dated the 1st of the go-live month, narration "Opening balances") via
   `PostJournal`: Dr cash/bank/wallet rows and one Dr 13xx line per investment; Cr 2101 and 2111 per member with `member_id`; Cr liability/equity
   rows; one 3901 line (Dr or Cr) for the difference, omitted when zero; zero amounts skipped. The
   advance ledger entries and investment register entries reference this journal.
7. The import becomes **Posted** with journal id, counts, totals, `posted_by`, `posted_at`; audit-log
   entry `opening_import_posted`.

### 4.4 After posting

- The import page is read-only (summary and a link to the opening voucher).
- `GenerateMonthlyDues` refuses months before the go-live month (`DomainRuleViolation`).
- `ApplyLateFees` skips dues with `opening = true`.
- `PaidThroughCalculator` needs no change: with an open opening due and no earlier deposit due it
  already returns null (no paid-through month), which the UI shows as "—".

## 5. Components

New module `app/Domain/GoLive`:

| Unit | Purpose |
|------|---------|
| `Models/OpeningImport` | the draft/posted record; policy: view = staff, upload/post = `Permission::OpeningImport`, delete = false |
| `Enums/OpeningImportStatus` | Draft, Posted (HasLabel/HasColor/HasIcon) |
| `Services/OpeningWorkbookReader` | xlsx → typed rows (maatwebsite/excel); no validation |
| `Services/OpeningImportValidator` | rows → `list<OpeningImportError>` (sheet, row, column, translation key) |
| `Services/OpeningBalanceSheet` | validated rows → journal lines + summary (pure; used by preview and post, so they agree) |
| `Data/OpeningMemberRow`, `Data/OpeningSocietyRow`, `Data/OpeningInvestmentRow`, `Data/OpeningImportError` | `final readonly` DTOs, money as `Money` |
| `Actions/UploadOpeningImport`, `Actions/PostOpeningImport` | the two writes |
| `Exports/OpeningTemplate` | the template download |

Elsewhere: migration (`opening_imports`; `dues.opening` boolean default false; advance kind `opening`),
`ShareChanger` fee-skip option, the generation/late-fee/paid-through guards above, and a Settings
cluster page **Go-live import** (upload, error table, preview of members and opening TB, T3 post
showing the totals and requiring the typed word). All strings in `lang/bn` and `lang/en`.

## 6. Errors

- Validation problems never throw; they are listed (sheet, row, column, message) and block posting.
- Unreadable file / wrong sheets / missing headers → `DomainRuleViolation` on upload.
- Any failure during post rolls everything back; the draft stays as it was.
- A second post (double click, two tabs) finds the row already Posted under lock → `DomainRuleViolation`.

## 7. Testing

- Reader: Bangla and English digits, blanks, decimals > 2 refused, header order independent.
- Validator: each rule above, one dataset row per violation.
- `OpeningBalanceSheet`: Σ debit = Σ credit for random sheets; 3901 line sign and omission.
- `PostOpeningImport`: happy path (members with kept numbers and next number continues; lots from
  joined month with no registration due; advance entries; opening dues; journal balanced; TB = sheet;
  2101/2111 sub-ledgers per member = sheet; investment register = GL 13xx; integrity checks clean); each precondition; double post
  under lock; authorization.
- Afterwards: generating the go-live month applies advance to the new dues; generating a month before
  go-live is refused; no late fee on opening dues; paying an opening Deposit due credits 2101 and an
  opening Late-fee due credits 4121.
- Page: access by role, upload shows errors, T3 post, read-only after.

## 8. Out of scope

Month-by-month history; batch imports; discard/undo; welcome SMS; staff users; past investment
income or closed investments; open (unapproved) payments.
