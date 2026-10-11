# Financial calculation rules

How every amount in the app is calculated, as implemented. If anything here looks wrong for your somiti, raise it with the committee **before** changing code: these rules decide real money.

## Money basics

- **No decimals in storage.** Every amount is a whole number of **poisha** (৳1 = 100 poisha) in a `BIGINT …_poisha` column, handled by one `Money` value object (`app/Support/Money/Money.php`). There is no floating-point arithmetic anywhere money is involved.
- **Percentages** are whole **basis points** (1% = 100 bps).
- **Rounding** happens only at named points, always half-up (`app/Support/Money/Rounding.php`): late-fee percentages, year-end appropriation lines, the 50% loss-offset limit.
- **Splitting** an amount (dividends, a deceased member's payout between nominees) always uses the **largest-remainder** method (`Allocator::largestRemainder`): the parts add up exactly to the total; leftover poisha go to the largest remainders, ties to the earlier key.
- **Display** never calculates; it formats an existing amount (Bangla digits in Bangla).

## Chart of accounts (codes used in the rules below)

| Code | Account | Code | Account |
|---|---|---|---|
| 1101 | Cash in hand | 2101 | Member savings deposits (per member) |
| 1111 | Bank | 2111 | Member advance (per member) |
| 1121 / 1122 | bKash / Nagad | 2201 | Dividend payable (per member) |
| 1301–1399 | Investments (register) | 2211 | Cooperative development fund payable |
| 3101 | Share capital | 2301 | Exit settlements payable (per member) |
| 3201 / 3202 / 3203 | Reserve / bad-debt / other funds | 3901 | Accumulated surplus |
| 4101 | Registration fee income | 4111 | Service charge income |
| 4121 | Late fee income | 4131 | Exit fee income |
| 4201 | Investment profit | 5101–5199 | Expenses (5104 bank charges, 5106 tax at source, 5201 impairment) |

## Rate plans

A rate plan sets, from a given month: **share unit** (monthly deposit per share), **service charge per share**, **registration fee per share**, **due day** (1–28), **grace days** (0–60), late-fee rule, advance policy, registration-fee policy on increase, and the order dues are paid. Plans are approved by the president and the secretary and never change afterwards; a correction is a new plan for a later month.

## Monthly dues (BR-2, `MonthlyDueBuilder`)

For each active member and each share lot active in the month:
- **Deposit due** = shares × share unit (goes to the member's savings 2101 when paid).
- **Service-charge due** = shares × service charge per share (income 4111 when paid).

Each due stores a copy of the plan it used, so changing rates later never alters it. Due date = due day of the month. Members whose exit month has passed get no dues. Generating a month twice creates nothing new.

## Registration fee

- New shares: shares × registration fee per share, as a registration due (4101 when paid).
- When a plan raises the fee under the "difference" policy, existing shares get a top-up due for the difference.

## Late fee (BR-10, BR-11)

Late from **due date + grace days**. One fee per member per month, attached to the deposit due:
- **Fixed**: the fixed amount.
- **Percent**: base × rate (half-up), base = deposit only, deposit + service charge, or the whole outstanding; capped if a cap is set.
- Frequency: once, or each month until paid. Runs daily; running again adds nothing.

## Paying dues (BR-13, `AllocationEngine`)

When a payment is approved it settles open dues **oldest month first**, and within a month in the plan's payment order (default: late fee → service charge → registration → deposit). What is left:
- **Apply at current rate** (default): goes to the member's **advance** (2111) and is applied automatically to future dues when they are generated, at the rate of that month.
- **Lock prepaid months**: whole future months (up to 24) are created now at today's rate and marked paid; the rest goes to advance.

Receipt voucher (RV): Dr Cash/Bank/bKash/Nagad; Cr 2101 (deposits), 4111 (service), 4101 (registration), 4121 (late fee), 2111 (surplus).

## Advance

- Applied automatically after dues generation and after late fees, oldest due first, only to dues due by the end of the current month: JV Dr 2111, Cr by due type.
- Refund: PV Dr 2111, Cr cash/bank; cannot exceed the balance; at or above the threshold (default ৳5,000) the president must do it.
- "Paid through month X" is always calculated, never stored.

## Expenses and transfers

- Expense: PV Dr expense account, Cr paying account. The paying account must hold the amount (FundsGuard, all-time balance).
- Transfer: CV Dr destination (+ Dr 5104 charge), Cr source.

## Investments

Approval: PV Dr 13xx, Cr source. Income: RV Dr bank (net) + Dr 5106 (tax), Cr 4201. Write-down: JV Dr 5201, Cr 13xx. Closing: RV with any gain to 4201 or loss to 5201. Statutory limit warnings are recorded, not enforced.

## Member exit (BR-22 as built, `ExitCalculator`, `ApproveExit`)

Settlement = savings (2101) + unused advance (2111) + unpaid dividends (2201) − what the member still owes − exit fee.
1. Dues after the exit month are cancelled; anything paid on them returns to advance.
2. Unpaid deposits up to the exit month are cancelled (savings never made are not a debt).
3. Savings move to advance; advance settles service charges, registration and late fees still owed.
4. If the member still owes more than they hold, approval is refused.
5. Settlement JV: Dr 2111 + Dr 2201, Cr 4131 (fee) + Cr 2301; the member becomes "exited"; **all shares end from the next month**.
6. Payout: PV Dr 2301, Cr cash/bank; a deceased member's payout is split between nominees by their shares (largest remainder).

Note: refundable share capital (3101), listed in BR-22, is not part of the settlement as built (see known gaps).

## Year-end (BR-20, BR-21, `YearEndCalculator`)

1. **Net profit** = income − expenses of the fiscal year (excluding the year-end's own entries).
2. **Loss offset** (s.34(4)) = the smaller of 50% of profit (half-up) and the prior accumulated loss.
3. **Appropriation** from net profit (or profit after offset, if `SOMITI_STATUTORY_BASE=after_loss_offset`), each line half-up: reserve fund (default 15%, at least 15%), cooperative development fund (3%), bad-debt fund (0–10%; at least 10% for financing societies), other funds (0–10%).
4. **Dividend pool** = net profit − offset − appropriations.
5. **Dividends** are shared by **share-months** (shares held in each month of the year, summed) using largest remainder; the lines add up exactly to the pool. **Members whose exit is approved are left out.**
6. Posting: closing JV (income/expense → 3901), appropriation JV (3201/2211/3202/3203 and per-member 2201), dividend lines, the year is closed and the next one opened.
7. Dividends are then paid in cash (PV Dr 2201) or credited to savings (JV Dr 2201, Cr 2101).

## Corrections

Posted vouchers never change. Corrections are **reversals** (a mirror voucher with a reason, linked to the original) or, for prices, a **new rate plan version**. Payments, expenses, transfers and vouchers can be reversed; investments, exits, year-ends, dividend settlements and refunds have no reversal flow yet (known gap).

## Checks that prove the books add up

The nightly **books self-check** (`somiti:integrity:check`, 02:00) runs 15 checks, including: every voucher balances; the trial balance is zero; control accounts equal their sub-ledgers (savings, advance, dividends, exits, investments); every due's paid amount equals its allocations; voucher numbers have no gaps; the hash chain is unbroken; year-end totals add up; exited members have zero balances and **hold no shares**. Failures alert the super admins and accountants (bell, email, SMS).

## Same figure in different places — how they relate

| Figure | Dashboard | Report | Why they can differ |
|---|---|---|---|
| Collected this month | approved payments with a *received* date in the month (1st–today) | Cash book / Receipts & payments: vouchers dated in the period; Collection summary: by *due month* | A payment reversed later drops out of the dashboard for its original month, while the cash book shows both the receipt and the reversal; the collection summary also counts advance applied to that month's dues |
| Members overdue | same rule as the Defaulters report (unpaid dues past their due date) | Defaulters | none — they now use one rule |
| Savings, cash, bank | ledger balance on today's date | Trial balance / ledger | none — same journal lines |
| Paid through | estimate from the advance | member statement | the portal's estimate counts from the last paid month; the payment preview counts from the last deposit month |
