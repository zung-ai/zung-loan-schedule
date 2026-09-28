<?php

declare(strict_types=1);

namespace Zung\LoanSchedule\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zung\LoanSchedule\InvalidLoanException;
use Zung\LoanSchedule\LoanSchedule;
use Zung\LoanSchedule\Money;

final class LoanScheduleTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function loan(array $overrides = []): array
    {
        $loan = array_merge([
            'principal' => 12000,
            'loan_term' => 12,
            'repayment_frequency' => 1,
            'repayment_frequency_type' => 'months',
            'interest_rate' => 2,
            'interest_rate_type' => 'month',
            'interest_methodology' => 'declining_balance',
            'amortization_method' => 'equal_installments',
            'disbursement_date' => '2026-01-15',
            'first_payment_date' => '2026-02-15',
        ], $overrides);

        return array_filter($loan, static fn ($v) => $v !== '__unset__');
    }

    /**
     * @return array<string, mixed>
     */
    private static function flat(array $overrides = []): array
    {
        return self::loan(array_merge(['interest_methodology' => 'flat', 'amortization_method' => '__unset__'], $overrides));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function zungVectors(): iterable
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/zung-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $snake = static fn (string $k): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $k));

        foreach ($data['vectors'] as $i => $vector) {
            $params = [];
            foreach ($vector['params'] as $key => $value) {
                $params[$snake($key)] = $value;
            }

            yield sprintf('#%d %s', $i, $vector['name']) => [$params, $vector['expected']];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $expected
     */
    #[DataProvider('zungVectors')]
    public function testMatchesZungCoreBankingExactly(array $params, array $expected): void
    {
        $schedule = LoanSchedule::generate($params);

        self::assertCount(count($expected['installments']), $schedule['installments']);
        foreach ($expected['installments'] as $i => $row) {
            $actual = $schedule['installments'][$i];
            self::assertSame($row['installment'], $actual['installment']);
            self::assertSame($row['fromDate'], $actual['from_date']);
            self::assertSame($row['dueDate'], $actual['due_date']);
            self::assertSame((float) $row['principal'], $actual['principal'], "installment {$row['installment']} principal");
            self::assertSame((float) $row['interest'], $actual['interest'], "installment {$row['installment']} interest");
            self::assertSame((float) $row['totalDue'], $actual['total_due'], "installment {$row['installment']} total_due");
        }
        self::assertSame((float) $expected['totalPrincipal'], $schedule['total_principal']);
        self::assertSame((float) $expected['totalInterest'], $schedule['total_interest']);

        self::assertSame(0.0, $schedule['installments'][count($schedule['installments']) - 1]['balance']);
        self::assertSame(Money::round($schedule['total_principal'] + $schedule['total_interest'], 2), $schedule['total_repayable']);
        self::assertSame($schedule['installments'][0]['due_date'], $schedule['first_payment_date']);
        self::assertSame($schedule['installments'][count($schedule['installments']) - 1]['due_date'], $schedule['maturity_date']);
    }

    public function testTextbookEqualInstallmentLoan(): void
    {
        $s = LoanSchedule::generate(self::loan());

        self::assertCount(12, $s['installments']);
        self::assertSame(0.02, $s['period_interest_rate']);
        self::assertSame([
            'installment' => 1, 'from_date' => '2026-01-15', 'due_date' => '2026-02-15',
            'principal' => 894.72, 'interest' => 240.0, 'total_due' => 1134.72, 'balance' => 11105.28,
        ], $s['installments'][0]);
        foreach (array_slice($s['installments'], 0, -1) as $row) {
            self::assertSame(1134.72, $row['total_due']);
        }
        self::assertSame(12000.0, $s['total_principal']);
        self::assertSame('2027-01-15', $s['maturity_date']);
    }

    public function testFlatInterestIsChargedOnFullPrincipal(): void
    {
        $s = LoanSchedule::generate(self::flat());

        foreach ($s['installments'] as $row) {
            self::assertSame(240.0, $row['interest']);
            self::assertSame(1000.0, $row['principal']);
        }
        self::assertSame(2880.0, $s['total_interest']);
    }

    public function testEqualPrincipalPaymentsShrinkInterest(): void
    {
        $s = LoanSchedule::generate(self::loan(['amortization_method' => 'equal_principal_payments']));

        self::assertSame(240.0, $s['installments'][0]['interest']);
        self::assertSame(20.0, $s['installments'][11]['interest']);
    }

    public function testGraceOnInterest(): void
    {
        $s = LoanSchedule::generate(self::loan(['grace_on_interest_charged' => 2]));

        self::assertSame([0.0, 0.0], [$s['installments'][0]['interest'], $s['installments'][1]['interest']]);
        self::assertGreaterThan(0, $s['installments'][2]['interest']);
    }

    public function testMonthlyDueDatesClampThenKeepThatDay(): void
    {
        $s = LoanSchedule::generate(self::loan(['disbursement_date' => '2026-01-01', 'first_payment_date' => '2026-01-31', 'loan_term' => 3]));

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-28'], array_column($s['installments'], 'due_date'));
        self::assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], array_column($s['installments'], 'from_date'));
    }

    public function testYearlyDueDatesMoveFeb29ToMar1(): void
    {
        $s = LoanSchedule::generate(self::loan([
            'repayment_frequency_type' => 'years', 'interest_rate_type' => 'year', 'interest_rate' => 12, 'loan_term' => 2,
            'disbursement_date' => '2028-01-01', 'first_payment_date' => '2028-02-29',
        ]));

        self::assertSame(['2028-02-29', '2029-03-01'], array_column($s['installments'], 'due_date'));
    }

    public function testDecimalPlacesZero(): void
    {
        $s = LoanSchedule::generate(self::loan(['principal' => 1000, 'loan_term' => 3, 'decimal_places' => 0]));

        foreach ($s['installments'] as $row) {
            self::assertSame(floor($row['principal']), $row['principal']);
            self::assertSame(floor($row['interest']), $row['interest']);
        }
        self::assertSame(1000.0, $s['total_principal']);
    }

    public function testFixedInstallmentSolvesInstallmentCount(): void
    {
        $s = LoanSchedule::generate(self::loan(['fixed_installment_amount' => 2000]));

        self::assertCount(7, $s['installments']);
        self::assertSame(0.0, $s['installments'][6]['balance']);
    }

    public function testLastInstallmentBelowThresholdIsMerged(): void
    {
        $plain = LoanSchedule::generate(self::flat(['principal' => 1000, 'loan_term' => 3]));
        $merged = LoanSchedule::generate(self::flat(['principal' => 1000, 'loan_term' => 3, 'principal_threshold_for_last_installment' => 400]));

        self::assertSame([333.33, 333.33, 333.34], array_column($plain['installments'], 'principal'));
        self::assertSame([333.33, 666.67], array_column($merged['installments'], 'principal'));
        self::assertSame([20.0, 40.0], array_column($merged['installments'], 'interest'));
        self::assertSame('2026-03-15', $merged['maturity_date']);

        self::assertSame($plain, LoanSchedule::generate(self::flat(['principal' => 1000, 'loan_term' => 3, 'principal_threshold_for_last_installment' => 300])));
    }

    // --- deliberate differences from Zung --------------------------------

    public function testWeeklyWithAnnualRateUses52Weeks(): void
    {
        $rate = LoanSchedule::periodInterestRate(12, 'year', 'weeks');

        self::assertSame(12 / 52 * 1 / 100, $rate);
        self::assertNotSame(12 / 365 / 100, $rate);

        $s = LoanSchedule::generate(self::flat(['principal' => 10000, 'repayment_frequency_type' => 'weeks', 'loan_term' => 52, 'interest_rate' => 12, 'interest_rate_type' => 'year']));
        self::assertSame(23.08, $s['installments'][0]['interest']);
        self::assertSame(1200.16, $s['total_interest']);
    }

    public function testFixedInstallmentThatNeverPaysOffIsAnError(): void
    {
        $this->expectException(InvalidLoanException::class);
        LoanSchedule::generate(self::loan(['fixed_installment_amount' => 200]));
    }

    public function testZeroRateEqualInstallmentsSplitEvenly(): void
    {
        $s = LoanSchedule::generate(self::loan(['interest_rate' => 0]));

        foreach ($s['installments'] as $row) {
            self::assertSame(1000.0, $row['principal']);
            self::assertSame(0.0, $row['interest']);
        }
        self::assertSame(12000.0, $s['total_repayable']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return iterable<string, array{float, string, string, int, int, int, float}>
     */
    public static function periodRates(): iterable
    {
        yield '18%/year monthly' => [18, 'year', 'months', 1, 365, 30, 0.015];
        yield '1.5%/month quarterly' => [1.5, 'month', 'months', 3, 365, 30, 0.045];
        yield '36.5%/year daily' => [36.5, 'year', 'days', 1, 365, 30, 0.001];
        yield '3%/month daily' => [3, 'month', 'days', 1, 365, 30, 0.001];
        yield '1%/week monthly' => [1, 'week', 'months', 1, 365, 30, 0.04];
        yield '2%/month yearly' => [2, 'month', 'years', 1, 365, 30, 0.24];
        yield '0.1%/day weekly' => [0.1, 'day', 'weeks', 1, 365, 30, 0.007];
    }

    #[DataProvider('periodRates')]
    public function testPeriodInterestRate(float $rate, string $rateType, string $frequencyType, int $frequency, int $daysInYear, int $daysInMonth, float $expected): void
    {
        self::assertEqualsWithDelta($expected, LoanSchedule::periodInterestRate($rate, $rateType, $frequencyType, $frequency, $daysInYear, $daysInMonth), 1e-15);
    }

    /**
     * @return iterable<string, array{float, int, float}>
     */
    public static function roundings(): iterable
    {
        yield '1.005' => [1.005, 2, 1.01];
        yield '2.675' => [2.675, 2, 2.68];
        yield '0.285' => [0.285, 2, 0.29];
        yield '-0.125' => [-0.125, 2, -0.13];
        yield '2.5' => [2.5, 0, 3.0];
        yield '-2.5' => [-2.5, 0, -3.0];
        yield '1234.5678' => [1234.5678, 3, 1234.568];
        yield '0.004' => [0.004, 2, 0.0];
        yield '-0.004' => [-0.004, 2, 0.0];
    }

    #[DataProvider('roundings')]
    public function testMoneyRound(float $value, int $decimals, float $expected): void
    {
        $rounded = Money::round($value, $decimals);

        self::assertSame($expected, $rounded);
        self::assertFalse($rounded === 0.0 && fdiv(1.0, $rounded) < 0, 'must never return -0');
    }

    // --- validation -------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidLoans(): iterable
    {
        yield 'unknown parameter' => [['interest' => 5], 'Unknown parameter'];
        yield 'zero principal' => [['principal' => 0], '"principal"'];
        yield 'string principal' => [['principal' => '1000'], '"principal"'];
        yield 'NaN rate' => [['interest_rate' => NAN], '"interest_rate"'];
        yield 'negative rate' => [['interest_rate' => -1], '"interest_rate"'];
        yield 'fractional term' => [['loan_term' => 1.5], '"loan_term"'];
        yield 'term not a multiple of frequency' => [['loan_term' => 10, 'repayment_frequency' => 3], 'multiple'];
        yield 'unknown frequency type' => [['repayment_frequency_type' => 'fortnights'], '"repayment_frequency_type"'];
        yield 'singular frequency type' => [['repayment_frequency_type' => 'month'], '"repayment_frequency_type"'];
        yield 'plural rate type' => [['interest_rate_type' => 'months'], '"interest_rate_type"'];
        yield 'unknown methodology' => [['interest_methodology' => 'compound'], '"interest_methodology"'];
        yield 'declining without amortization' => [['amortization_method' => '__unset__'], '"amortization_method"'];
        yield 'flat with amortization' => [['interest_methodology' => 'flat'], 'only applies'];
        yield 'impossible date' => [['disbursement_date' => '2026-02-30'], '"disbursement_date"'];
        yield 'badly formatted date' => [['first_payment_date' => '15/02/2026'], '"first_payment_date"'];
        yield 'first payment before disbursement' => [['first_payment_date' => '2026-01-14'], 'cannot be before'];
        yield 'negative grace' => [['grace_on_interest_charged' => -1], '"grace_on_interest_charged"'];
        yield 'string exact-days flag' => [['exact_days_in_first_period' => 'yes'], '"exact_days_in_first_period"'];
        yield 'odd days_in_year' => [['days_in_year' => 366], '"days_in_year"'];
        yield 'string days_in_year' => [['days_in_year' => '360'], '"days_in_year"'];
        yield 'odd days_in_month' => [['days_in_month' => 28], '"days_in_month"'];
        yield 'too many decimals' => [['decimal_places' => 7], '"decimal_places"'];
        yield 'zero fixed installment' => [['fixed_installment_amount' => 0], '"fixed_installment_amount"'];
        yield 'fixed installment not covering interest' => [['fixed_installment_amount' => 240], "first period's interest (240)"];
        yield 'fixed installment over 10,000 installments' => [['interest_rate' => 0, 'fixed_installment_amount' => 1], 'too small: it would take 12000'];
        yield 'fixed installment on equal principal' => [['amortization_method' => 'equal_principal_payments', 'fixed_installment_amount' => 2000], "doesn't apply"];
        yield 'too many installments' => [['repayment_frequency_type' => 'days', 'interest_rate_type' => 'day', 'interest_rate' => 0.1, 'loan_term' => 10001], 'limited to'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidLoans')]
    public function testRejectsInvalidLoans(array $overrides, string $message): void
    {
        $this->expectException(InvalidLoanException::class);
        $this->expectExceptionMessage($message);

        LoanSchedule::generate(self::loan($overrides));
    }
}
