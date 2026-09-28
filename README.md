# Zung Loan Schedule

Loan repayment schedules for SACCOs, MFIs and lenders, in **PHP** and
**Node.js**, calculated exactly as [Zung](https://zung.ai) core banking
calculates them, to the cent.

- Flat rate, or reducing balance with **equal installments** (EMI) or **equal principal**
- Daily, weekly, monthly or yearly repayments, every N periods
- Rates per day, week, month or year, converted to the repayment period
- Grace on interest, daily interest, exact days in the first period
- `actual`/360/364/365-day years and `actual`/30/31-day months
- Solve the number of installments from a fixed installment amount
- Merge a too-small last installment into the one before it
- No dependencies: PHP 8.1+ (no extensions) and Node 18+ (TypeScript types included)

| | Package |
|---|---|
| PHP | `zung/loan-schedule` |
| Node.js | `zung-loan-schedule` |

## Install

```bash
composer require zung/loan-schedule
```

```bash
npm install zung-loan-schedule
```

## Quick start

A 12,000 loan at 2% a month, reducing balance, 12 equal monthly installments:

### PHP

```php
use Zung\LoanSchedule\LoanSchedule;

$schedule = LoanSchedule::generate([
    'principal' => 12000,
    'loan_term' => 12,
    'repayment_frequency_type' => 'months',
    'interest_rate' => 2,
    'interest_rate_type' => 'month',
    'interest_methodology' => 'declining_balance',
    'amortization_method' => 'equal_installments',
    'disbursement_date' => '2026-01-15',
    'first_payment_date' => '2026-02-15',
]);

foreach ($schedule['installments'] as $row) {
    printf("%2d  %s  %9.2f  %8.2f  %9.2f  %10.2f\n",
        $row['installment'], $row['due_date'], $row['principal'], $row['interest'], $row['total_due'], $row['balance']);
}

echo $schedule['total_interest'];   // 1616.59
```

### Node.js

```js
import { generateSchedule } from 'zung-loan-schedule';

const schedule = generateSchedule({
  principal: 12000,
  loanTerm: 12,
  repaymentFrequencyType: 'months',
  interestRate: 2,
  interestRateType: 'month',
  interestMethodology: 'declining_balance',
  amortizationMethod: 'equal_installments',
  disbursementDate: '2026-01-15',
  firstPaymentDate: '2026-02-15',
});

console.table(schedule.installments);
console.log(schedule.totalInterest); // 1616.59
```

```
 #  due date     principal  interest  total due     balance
 1  2026-02-15      894.72    240.00    1134.72    11105.28
 2  2026-03-15      912.61    222.11    1134.72    10192.67
 ...
12  2027-01-15     1112.42     22.25    1134.67        0.00
```

## Parameters

PHP takes snake_case keys, Node.js camelCase. Everything else is identical.

| PHP / Node.js | | |
|---|---|---|
| `principal` | required | Amount lent, > 0. |
| `loan_term` / `loanTerm` | required | Length of the loan in `repayment_frequency_type` units. Must be a multiple of `repayment_frequency`. |
| `repayment_frequency` / `repaymentFrequency` | default `1` | Pay every N units: 3 with `months` is quarterly. |
| `repayment_frequency_type` / `repaymentFrequencyType` | required | `days`, `weeks`, `months` or `years`. |
| `interest_rate` / `interestRate` | required | Percentage per `interest_rate_type`, ≥ 0. `1.5` means 1.5%. |
| `interest_rate_type` / `interestRateType` | required | `day`, `week`, `month` or `year`. |
| `interest_methodology` / `interestMethodology` | required | `flat` or `declining_balance`. |
| `amortization_method` / `amortizationMethod` | for `declining_balance` | `equal_installments` (same payment each time) or `equal_principal_payments` (same principal, falling interest). |
| `disbursement_date` / `disbursementDate` | required | `YYYY-MM-DD`. |
| `first_payment_date` / `firstPaymentDate` | required | `YYYY-MM-DD`, on or after disbursement. Later due dates follow on from it. |
| `grace_on_interest_charged` / `graceOnInterestCharged` | default `0` | The first N installments carry no interest. |
| `interest_calculation_period` / `interestCalculationPeriod` | default `same` | `daily` charges interest per calendar day of each period (not for `equal_installments`). |
| `exact_days_in_first_period` / `exactDaysInFirstPeriod` | default `false` | Charge the first installment's interest by its exact number of days. |
| `days_in_year` / `daysInYear` | default `actual` | `actual`, 360, 364 or 365. |
| `days_in_month` / `daysInMonth` | default `actual` | `actual`, 30 or 31. |
| `fixed_installment_amount` / `fixedInstallmentAmount` | optional | Solve for how many installments this payment takes, instead of using `loan_term`. `flat` and `equal_installments` only. |
| `principal_threshold_for_last_installment` / `principalThresholdForLastInstallment` | optional | If the last installment's principal is below this, merge it into the one before. |
| `decimal_places` / `decimalPlaces` | default `2` | 0 to 6. |

Anything invalid throws `InvalidLoanException` (PHP) or `InvalidLoanError`
(Node.js) before any calculation, with a message naming the parameter.

## Output

| PHP / Node.js | |
|---|---|
| `installments` | One row per installment: `installment`, `from_date`/`fromDate`, `due_date`/`dueDate`, `principal`, `interest`, `total_due`/`totalDue`, and `balance` (principal still outstanding after it). |
| `total_principal`, `total_interest`, `total_repayable` | Totals (camelCase in Node.js). |
| `period_interest_rate` | The fraction charged per installment period, e.g. `0.02`. |
| `first_payment_date`, `maturity_date` | Due dates of the first and last installments. |

`LoanSchedule::periodInterestRate()` / `periodInterestRate()` and
`Money::round()` / `roundMoney()` are exported too.

## How it calculates

**Flat:** every installment is charged `period rate × original principal`,
and repays `principal ÷ installments`.

**Reducing balance, equal installments:** the payment is the standard
amortization formula `P·r·(1+r)ⁿ / ((1+r)ⁿ − 1)`; each installment's interest
is `r × outstanding balance`, and the rest of the payment reduces principal.

**Reducing balance, equal principal:** principal is `P ÷ n` each time;
interest is `r × outstanding balance`, so installments shrink.

In all three, each figure is rounded as it is calculated and the **last
installment absorbs any rounding difference**, so principal always adds up
exactly.

**Period rate** converts the quoted rate to the repayment period, where a
month is `days_in_month` days or 4 weeks, and a year is `days_in_year` days,
52 weeks or 12 months (with `actual`, taken from the disbursement date). For
example, 18% a year paid monthly is 1.5% per installment; 1.5% a month paid
every 3 months is 4.5%.

**Rounding** is half away from zero (1.005 → 1.01), implemented as an exact
port of PHP 8.4's `round()` in both languages, so results don't change with
the PHP version or between PHP and Node.js.

**Due dates:** each due date is one repayment period after the previous
one. Monthly dates clamp to shorter months and then keep that day, so a
first payment on Jan 31 gives Feb 28, then Mar 28, Apr 28, and so on.
Choose a first payment date on or before the 28th if you want every
installment on the same day. Yearly dates move Feb 29 to Mar 1.

## Matches Zung exactly, with three deliberate differences

This library was checked against Zung's own schedule engine
(`LoanService::generateRepaymentSchedule()`), run on **20,000 randomly
generated loans** (371,999 installments) covering every option above:
every installment's dates, principal, interest and total are identical in
both the PHP and Node.js versions, on PHP 8.2 and 8.4. A representative set
of those Zung-generated schedules ships as
[`fixtures/zung-vectors.json`](fixtures/zung-vectors.json), and both test
suites must reproduce it exactly.

Where Zung's engine has a bug, this library does the correct thing instead:

1. **Weekly repayments with an annual rate.** Zung divides the annual rate
   by the days in the year, charging the *daily* rate every week (about 7×
   too little interest). This library divides by 52.
2. **0% loans with equal installments.** Zung's formula divides by zero.
   This library splits the principal evenly.
3. **A fixed installment amount that can't be used.** Zung silently ignores
   `fixed_installment_amount` when it doesn't cover the first period's
   interest, or for equal-principal loans, and uses `loan_term` instead. This
   library throws an error saying why.

It is also stricter about input: a `loan_term` that isn't a multiple of
`repayment_frequency`, or a first payment before disbursement, is rejected
rather than producing a partial schedule.

## Not included

Fees, penalties, taxes and insurance; APR / effective-rate disclosure;
repayments, arrears and rescheduling; interest recalculation after early
payments; multi-tranche disbursements. This library produces the
contractual schedule at disbursement.

## Development

```bash
composer update && vendor/bin/phpunit      # PHP
cd node && npm test                        # Node.js
```

## License

MIT
