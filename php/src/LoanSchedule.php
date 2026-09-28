<?php

declare(strict_types=1);

namespace Zung\LoanSchedule;

/**
 * Loan repayment schedules, calculated the same way as Zung's core banking
 * engine (see README for the two deliberate differences).
 */
final class LoanSchedule
{
    public const VERSION = '0.1.0';

    public const REPAYMENT_FREQUENCY_TYPES = ['days', 'weeks', 'months', 'years'];
    public const INTEREST_RATE_TYPES = ['day', 'week', 'month', 'year'];
    public const INTEREST_METHODOLOGIES = ['flat', 'declining_balance'];
    public const AMORTIZATION_METHODS = ['equal_installments', 'equal_principal_payments'];

    private const MAX_INSTALLMENTS = 10000;
    private const WEEKS_IN_YEAR = 52;
    private const WEEKS_IN_MONTH = 4;

    private const PARAMS = [
        'principal', 'loan_term', 'repayment_frequency', 'repayment_frequency_type',
        'interest_rate', 'interest_rate_type', 'interest_methodology', 'amortization_method',
        'disbursement_date', 'first_payment_date', 'grace_on_interest_charged',
        'interest_calculation_period', 'exact_days_in_first_period', 'days_in_year', 'days_in_month',
        'fixed_installment_amount', 'principal_threshold_for_last_installment', 'decimal_places',
    ];

    /**
     * Converts a nominal rate (a percentage per $interestRateType) into the
     * fraction charged per installment period.
     *
     * Conventions: a month is $daysInMonth days or 4 weeks, a year is
     * $daysInYear days, 52 weeks or 12 months.
     *
     * @return float e.g. 0.015 for 1.5% per period.
     */
    public static function periodInterestRate(
        float $interestRate,
        string $interestRateType,
        string $repaymentFrequencyType,
        int $repaymentFrequency = 1,
        int $daysInYear = 365,
        int $daysInMonth = 30,
    ): float {
        $rate = $interestRate;

        if ($repaymentFrequencyType === 'days') {
            if ($interestRateType === 'year') {
                $rate = $rate / $daysInYear;
            }
            if ($interestRateType === 'month') {
                $rate = $rate / $daysInMonth;
            }
            if ($interestRateType === 'week') {
                $rate = $rate / 7;
            }
        }
        if ($repaymentFrequencyType === 'weeks') {
            // Zung's own engine divides by days-in-year here, charging a weekly
            // loan the daily rate (about 7x too little). This library uses 52 weeks.
            if ($interestRateType === 'year') {
                $rate = $rate / self::WEEKS_IN_YEAR;
            }
            if ($interestRateType === 'month') {
                $rate = $rate / self::WEEKS_IN_MONTH;
            }
            if ($interestRateType === 'day') {
                $rate = $rate * 7;
            }
        }
        if ($repaymentFrequencyType === 'months') {
            if ($interestRateType === 'year') {
                $rate = $rate / 12;
            }
            if ($interestRateType === 'week') {
                $rate = $rate * self::WEEKS_IN_MONTH;
            }
            if ($interestRateType === 'day') {
                $rate = $rate * $daysInMonth;
            }
        }
        if ($repaymentFrequencyType === 'years') {
            if ($interestRateType === 'month') {
                $rate = $rate * 12;
            }
            if ($interestRateType === 'week') {
                $rate = $rate * self::WEEKS_IN_YEAR;
            }
            if ($interestRateType === 'day') {
                $rate = $rate * $daysInYear;
            }
        }

        return $rate * $repaymentFrequency / 100;
    }

    /**
     * Builds a loan's repayment schedule.
     *
     * Parameters (snake_case):
     *  - principal                   (required) number > 0
     *  - loan_term                   (required) int, in repayment_frequency_type units; a multiple of repayment_frequency
     *  - repayment_frequency         int >= 1, default 1 (pay every N units)
     *  - repayment_frequency_type    (required) days | weeks | months | years
     *  - interest_rate               (required) percentage >= 0 per interest_rate_type, e.g. 1.5
     *  - interest_rate_type          (required) day | week | month | year
     *  - interest_methodology        (required) flat | declining_balance
     *  - amortization_method         equal_installments | equal_principal_payments (required for declining_balance)
     *  - disbursement_date           (required) Y-m-d
     *  - first_payment_date          (required) Y-m-d, on or after disbursement_date
     *  - grace_on_interest_charged   int >= 0, default 0: leading installments charged no interest
     *  - interest_calculation_period same | daily, default same
     *  - exact_days_in_first_period  bool, default false
     *  - days_in_year                'actual' | 360 | 364 | 365, default 'actual'
     *  - days_in_month               'actual' | 30 | 31, default 'actual'
     *  - fixed_installment_amount    number > 0: solve for the installment count instead of using loan_term
     *  - principal_threshold_for_last_installment number >= 0: merge a smaller final installment into the one before
     *  - decimal_places              int 0-6, default 2
     *
     * @param array<string, mixed> $params
     *
     * @return array{
     *     installments: list<array{installment: int, from_date: string, due_date: string, principal: float, interest: float, total_due: float, balance: float}>,
     *     total_principal: float,
     *     total_interest: float,
     *     total_repayable: float,
     *     period_interest_rate: float,
     *     first_payment_date: string,
     *     maturity_date: string
     * }
     *
     * @throws InvalidLoanException
     */
    public static function generate(array $params): array
    {
        $p = self::validate($params);
        $dp = $p['decimal_places'];
        $round = static fn (float $value): float => Money::round($value, $dp);

        $resolvedDaysInYear = $p['days_in_year'] === 'actual'
            ? (Dates::isLeapYear(Dates::year($p['disbursement_date'])) ? 366 : 365)
            : $p['days_in_year'];
        $resolvedDaysInMonth = $p['days_in_month'] === 'actual'
            ? Dates::monthLength($p['disbursement_date'])
            : $p['days_in_month'];

        $rate = self::periodInterestRate(
            $p['interest_rate'],
            $p['interest_rate_type'],
            $p['repayment_frequency_type'],
            $p['repayment_frequency'],
            $resolvedDaysInYear,
            $resolvedDaysInMonth,
        );

        $period = intdiv($p['loan_term'], $p['repayment_frequency']);
        if ($p['fixed_installment_amount'] !== null) {
            // Zung silently falls back to loan_term when the amount can't be
            // solved for; this library says so instead.
            $solved = self::solveInstallmentCount($p, $p['fixed_installment_amount'], $p['principal'], $rate);
            if ($solved === null) {
                throw new InvalidLoanException(sprintf(
                    '"fixed_installment_amount" must be more than the first period\'s interest (%s), or the loan never pays off.',
                    Money::round($p['principal'] * $rate, $dp)
                ));
            }
            if ($solved > self::MAX_INSTALLMENTS) {
                throw new InvalidLoanException(sprintf('"fixed_installment_amount" is too small: it would take %d installments.', $solved));
            }
            $period = $solved;
        }

        $useDaily = $p['interest_calculation_period'] === 'daily' || $p['exact_days_in_first_period'];
        $dailyRate = $useDaily
            ? self::periodInterestRate($p['interest_rate'], $p['interest_rate_type'], 'days', 1, $resolvedDaysInYear, $resolvedDaysInMonth)
            : null;

        $principal = $p['principal'];
        $equalInstallments = $p['interest_methodology'] === 'declining_balance' && $p['amortization_method'] === 'equal_installments';
        $amortizedPayment = $equalInstallments ? $round(self::amortizedPayment($rate, $principal, $period)) : null;

        $balance = $round($principal);
        $fromDate = $p['disbursement_date'];
        $dueDate = $p['first_payment_date'];
        $totalPrincipal = 0.0;
        $totalInterest = 0.0;
        $installments = [];

        for ($i = 1; $i <= $period; $i++) {
            $daysInPeriod = (float) (Dates::diffInDays($fromDate, $dueDate) + 1);
            $dailyApplies = $dailyRate !== null
                && ($p['interest_calculation_period'] === 'daily' || ($i === 1 && $p['exact_days_in_first_period']));

            if ($p['interest_methodology'] === 'flat') {
                $installmentPrincipal = $round($principal / $period);
                $interest = $dailyApplies ? $round($dailyRate * $daysInPeriod * $principal) : $round($rate * $principal);
            } elseif ($equalInstallments) {
                // The daily/exact-day option doesn't apply to equal installments:
                // the payment is fixed from the period rate (same as Zung).
                $interest = $round($rate * $balance);
                $installmentPrincipal = $round($amortizedPayment - $interest);
            } else {
                $installmentPrincipal = $round($principal / $period);
                $interest = $dailyApplies ? $round($dailyRate * $daysInPeriod * $balance) : $round($rate * $balance);
            }

            $rowInterest = $p['grace_on_interest_charged'] >= $i ? 0.0 : $interest;
            $rowPrincipal = $i === $period ? $round($balance) : $installmentPrincipal;
            $balance = $balance - $installmentPrincipal;

            $installments[] = [
                'installment' => $i,
                'from_date' => $fromDate,
                'due_date' => $dueDate,
                'principal' => $rowPrincipal,
                'interest' => $rowInterest,
                'total_due' => $round($rowPrincipal + $rowInterest),
                'balance' => 0.0, // filled in below, after any final-installment merge
            ];
            $totalPrincipal += $rowPrincipal;
            $totalInterest += $rowInterest;

            $fromDate = Dates::addDays($dueDate, 1);
            $dueDate = self::nextDueDate($dueDate, $p['repayment_frequency'], $p['repayment_frequency_type']);
        }

        $threshold = $p['principal_threshold_for_last_installment'];
        if ($threshold !== null && $threshold > 0 && count($installments) > 1) {
            $last = $installments[count($installments) - 1];
            if ($last['principal'] < $threshold) {
                $s = count($installments) - 2;
                $installments[$s]['principal'] = $round($installments[$s]['principal'] + $last['principal']);
                $installments[$s]['interest'] = $round($installments[$s]['interest'] + $last['interest']);
                $installments[$s]['total_due'] = $round($installments[$s]['principal'] + $installments[$s]['interest']);
                array_pop($installments);
            }
        }

        $outstanding = $round($principal);
        foreach ($installments as $k => $row) {
            $outstanding = $round($outstanding - $row['principal']);
            $installments[$k]['balance'] = $outstanding;
        }

        return [
            'installments' => $installments,
            'total_principal' => $round($totalPrincipal),
            'total_interest' => $round($totalInterest),
            'total_repayable' => $round($totalPrincipal + $totalInterest),
            'period_interest_rate' => $rate,
            'first_payment_date' => $installments[0]['due_date'],
            'maturity_date' => $installments[count($installments) - 1]['due_date'],
        ];
    }

    private static function nextDueDate(string $date, int $frequency, string $type): string
    {
        return match ($type) {
            'months' => Dates::addMonthsNoOverflow($date, $frequency),
            'years' => Dates::addYears($date, $frequency),
            'weeks' => Dates::addDays($date, 7 * $frequency),
            default => Dates::addDays($date, $frequency),
        };
    }

    /**
     * The classic EMI formula. At 0% it is the limit of the formula: an even
     * split of the principal (Zung's own engine divides by zero here).
     */
    private static function amortizedPayment(float $rate, float $balance, int $period): float
    {
        if ($rate == 0.0) {
            return $balance / $period;
        }

        return ($rate * $balance * pow(1 + $rate, $period)) / (pow(1 + $rate, $period) - 1);
    }

    /**
     * How many installments a fixed payment amount pays the loan off in, or
     * null when that isn't defined (equal principal payments, or a payment
     * that doesn't even cover the first period's interest).
     *
     * @param array<string, mixed> $p
     */
    private static function solveInstallmentCount(array $p, float $amount, float $principal, float $rate): ?int
    {
        $firstPeriodInterest = $principal * $rate;

        if ($p['interest_methodology'] === 'flat') {
            if ($amount <= $firstPeriodInterest) {
                return null;
            }

            return (int) ceil($principal / ($amount - $firstPeriodInterest));
        }

        if ($p['amortization_method'] === 'equal_installments') {
            if ($rate <= 0) {
                return (int) ceil($principal / $amount);
            }
            if ($amount <= $firstPeriodInterest) {
                return null;
            }
            $x = $amount / ($amount - $firstPeriodInterest);

            return (int) ceil(log($x) / log(1 + $rate));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function validate(array $params): array
    {
        $unknown = array_diff(array_keys($params), self::PARAMS);
        if ($unknown !== []) {
            throw new InvalidLoanException(sprintf(
                'Unknown parameter(s): %s. Allowed: %s.',
                implode(', ', $unknown),
                implode(', ', self::PARAMS)
            ));
        }

        $p = [
            'principal' => self::requireNumber($params['principal'] ?? null, 'principal', 0.0, true),
            'loan_term' => self::requireInt($params['loan_term'] ?? null, 'loan_term', 1),
            'repayment_frequency' => self::requireInt($params['repayment_frequency'] ?? 1, 'repayment_frequency', 1),
            'repayment_frequency_type' => self::requireOneOf($params['repayment_frequency_type'] ?? null, 'repayment_frequency_type', self::REPAYMENT_FREQUENCY_TYPES),
            'interest_rate' => self::requireNumber($params['interest_rate'] ?? null, 'interest_rate', 0.0, false),
            'interest_rate_type' => self::requireOneOf($params['interest_rate_type'] ?? null, 'interest_rate_type', self::INTEREST_RATE_TYPES),
            'interest_methodology' => self::requireOneOf($params['interest_methodology'] ?? null, 'interest_methodology', self::INTEREST_METHODOLOGIES),
            'amortization_method' => null,
            'disbursement_date' => Dates::require($params['disbursement_date'] ?? null, 'disbursement_date'),
            'first_payment_date' => Dates::require($params['first_payment_date'] ?? null, 'first_payment_date'),
            'grace_on_interest_charged' => self::requireInt($params['grace_on_interest_charged'] ?? 0, 'grace_on_interest_charged', 0),
            'interest_calculation_period' => self::requireOneOf($params['interest_calculation_period'] ?? 'same', 'interest_calculation_period', ['same', 'daily']),
            'exact_days_in_first_period' => $params['exact_days_in_first_period'] ?? false,
            'days_in_year' => $params['days_in_year'] ?? 'actual',
            'days_in_month' => $params['days_in_month'] ?? 'actual',
            'fixed_installment_amount' => null,
            'principal_threshold_for_last_installment' => null,
            'decimal_places' => self::requireInt($params['decimal_places'] ?? 2, 'decimal_places', 0),
        ];

        if ($p['interest_methodology'] === 'declining_balance') {
            $p['amortization_method'] = self::requireOneOf($params['amortization_method'] ?? null, 'amortization_method', self::AMORTIZATION_METHODS);
        } elseif (($params['amortization_method'] ?? null) !== null) {
            throw new InvalidLoanException('"amortization_method" only applies to declining_balance loans.');
        }

        if ($p['loan_term'] % $p['repayment_frequency'] !== 0) {
            throw new InvalidLoanException('"loan_term" must be a whole multiple of "repayment_frequency".');
        }
        if (intdiv($p['loan_term'], $p['repayment_frequency']) > self::MAX_INSTALLMENTS) {
            throw new InvalidLoanException(sprintf('A schedule is limited to %d installments.', self::MAX_INSTALLMENTS));
        }
        if ($p['first_payment_date'] < $p['disbursement_date']) {
            throw new InvalidLoanException('"first_payment_date" cannot be before "disbursement_date".');
        }
        if (!is_bool($p['exact_days_in_first_period'])) {
            throw new InvalidLoanException('"exact_days_in_first_period" must be a boolean.');
        }
        if (!in_array($p['days_in_year'], ['actual', 360, 364, 365], true)) {
            throw new InvalidLoanException('"days_in_year" must be "actual", 360, 364 or 365.');
        }
        if (!in_array($p['days_in_month'], ['actual', 30, 31], true)) {
            throw new InvalidLoanException('"days_in_month" must be "actual", 30 or 31.');
        }
        if ($p['decimal_places'] > 6) {
            throw new InvalidLoanException('"decimal_places" must be between 0 and 6.');
        }
        if (($params['fixed_installment_amount'] ?? null) !== null) {
            $p['fixed_installment_amount'] = self::requireNumber($params['fixed_installment_amount'], 'fixed_installment_amount', 0.0, true);
            if ($p['amortization_method'] === 'equal_principal_payments') {
                throw new InvalidLoanException('"fixed_installment_amount" doesn\'t apply to equal_principal_payments, whose installments vary by design.');
            }
        }
        if (($params['principal_threshold_for_last_installment'] ?? null) !== null) {
            $p['principal_threshold_for_last_installment'] = self::requireNumber($params['principal_threshold_for_last_installment'], 'principal_threshold_for_last_installment', 0.0, false);
        }

        return $p;
    }

    private static function requireNumber(mixed $value, string $name, float $min, bool $exclusive): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidLoanException(sprintf('"%s" is required and must be a finite number.', $name));
        }
        $value = (float) $value;
        if ($exclusive ? $value <= $min : $value < $min) {
            throw new InvalidLoanException(sprintf('"%s" must be %s %s.', $name, $exclusive ? 'greater than' : 'at least', $min + 0));
        }

        return $value;
    }

    private static function requireInt(mixed $value, string $name, int $min): int
    {
        if (!is_int($value) || $value < $min) {
            throw new InvalidLoanException(sprintf('"%s" must be an integer of at least %d.', $name, $min));
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function requireOneOf(mixed $value, string $name, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidLoanException(sprintf('"%s" must be one of: %s.', $name, implode(', ', $allowed)));
        }

        return $value;
    }
}
