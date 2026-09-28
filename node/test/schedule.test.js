import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { generateSchedule, periodInterestRate, roundMoney, InvalidLoanError } from '../src/index.js';

const { vectors } = JSON.parse(readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'fixtures', 'zung-vectors.json'),
  'utf8',
));

function loan(overrides = {}) {
  return {
    principal: 12000,
    loanTerm: 12,
    repaymentFrequency: 1,
    repaymentFrequencyType: 'months',
    interestRate: 2,
    interestRateType: 'month',
    interestMethodology: 'declining_balance',
    amortizationMethod: 'equal_installments',
    disbursementDate: '2026-01-15',
    firstPaymentDate: '2026-02-15',
    ...overrides,
  };
}

describe('matches Zung core banking exactly', () => {
  for (const vector of vectors) {
    test(`${vector.name}: ${vector.params.interestMethodology}/${vector.params.amortizationMethod ?? '-'} every ${vector.params.repaymentFrequency} ${vector.params.repaymentFrequencyType}`, () => {
      const schedule = generateSchedule(vector.params);

      assert.deepEqual(
        schedule.installments.map(({ balance, ...row }) => row),
        vector.expected.installments,
      );
      assert.equal(schedule.totalPrincipal, vector.expected.totalPrincipal);
      assert.equal(schedule.totalInterest, vector.expected.totalInterest);
    });
  }
});

describe('schedule shape', () => {
  test('balance runs down to exactly zero and totals add up', () => {
    for (const vector of vectors) {
      const s = generateSchedule(vector.params);
      assert.equal(s.installments.at(-1).balance, 0, vector.name);
      assert.equal(s.totalRepayable, roundMoney(s.totalPrincipal + s.totalInterest, 2));
      assert.equal(s.firstPaymentDate, s.installments[0].dueDate);
      assert.equal(s.maturityDate, s.installments.at(-1).dueDate);
      for (const row of s.installments) {
        assert.equal(row.totalDue, roundMoney(row.principal + row.interest, 2));
      }
    }
  });

  test('a textbook equal-installment loan', () => {
    const s = generateSchedule(loan());

    assert.equal(s.installments.length, 12);
    assert.equal(s.periodInterestRate, 0.02);
    assert.deepEqual(s.installments[0], {
      installment: 1, fromDate: '2026-01-15', dueDate: '2026-02-15',
      principal: 894.72, interest: 240, totalDue: 1134.72, balance: 11105.28,
    });
    // Every installment but the last is the same payment.
    assert.ok(s.installments.slice(0, -1).every((row) => row.totalDue === 1134.72));
    assert.equal(s.totalPrincipal, 12000);
    assert.equal(s.maturityDate, '2027-01-15');
  });

  test('flat interest is charged on the full principal every installment', () => {
    const s = generateSchedule(loan({ interestMethodology: 'flat', amortizationMethod: undefined }));

    assert.ok(s.installments.every((row) => row.interest === 240 && row.principal === 1000));
    assert.equal(s.totalInterest, 2880);
  });

  test('equal principal payments shrink the interest as the balance falls', () => {
    const s = generateSchedule(loan({ amortizationMethod: 'equal_principal_payments' }));

    assert.ok(s.installments.every((row) => row.principal === 1000));
    assert.equal(s.installments[0].interest, 240);
    assert.equal(s.installments.at(-1).interest, 20);
  });

  test('grace on interest charges nothing for the first N installments', () => {
    const s = generateSchedule(loan({ graceOnInterestCharged: 2 }));

    assert.deepEqual(s.installments.slice(0, 3).map((row) => row.interest > 0), [false, false, true]);
  });

  test('monthly due dates clamp to short months and then keep that day, like Zung', () => {
    const s = generateSchedule(loan({ disbursementDate: '2026-01-01', firstPaymentDate: '2026-01-31', loanTerm: 3 }));

    assert.deepEqual(s.installments.map((row) => row.dueDate), ['2026-01-31', '2026-02-28', '2026-03-28']);
    assert.deepEqual(s.installments.map((row) => row.fromDate), ['2026-01-01', '2026-02-01', '2026-03-01']);
  });

  test('yearly due dates move Feb 29 to Mar 1 in a common year, like Zung', () => {
    const s = generateSchedule(loan({
      repaymentFrequencyType: 'years', interestRateType: 'year', interestRate: 12, loanTerm: 2,
      disbursementDate: '2028-01-01', firstPaymentDate: '2028-02-29',
    }));

    assert.deepEqual(s.installments.map((row) => row.dueDate), ['2028-02-29', '2029-03-01']);
  });

  test('a first payment on the disbursement date is allowed', () => {
    const s = generateSchedule(loan({ firstPaymentDate: '2026-01-15' }));

    assert.equal(s.installments[0].dueDate, '2026-01-15');
  });

  test('decimalPlaces changes the rounding', () => {
    const s = generateSchedule(loan({ principal: 1000, loanTerm: 3, decimalPlaces: 0 }));

    assert.ok(s.installments.every((row) => Number.isInteger(row.principal) && Number.isInteger(row.interest)));
    assert.equal(s.totalPrincipal, 1000);
  });

  test('fixedInstallmentAmount solves for the number of installments', () => {
    const s = generateSchedule(loan({ fixedInstallmentAmount: 2000 }));

    assert.equal(s.installments.length, 7);
    assert.equal(s.installments.at(-1).balance, 0);
  });

  test('a last installment below the threshold is merged into the one before it', () => {
    const base = { interestMethodology: 'flat', amortizationMethod: undefined, principal: 1000, loanTerm: 3 };
    const plain = generateSchedule(loan(base));
    const merged = generateSchedule(loan({ ...base, principalThresholdForLastInstallment: 400 }));

    assert.deepEqual(plain.installments.map((row) => row.principal), [333.33, 333.33, 333.34]);
    assert.deepEqual(merged.installments.map((row) => [row.principal, row.interest]), [[333.33, 20], [666.67, 40]]);
    assert.equal(merged.totalPrincipal, 1000);
    assert.equal(merged.installments.at(-1).balance, 0);
    assert.equal(merged.maturityDate, '2026-03-15');
  });

  test('a threshold the last installment already meets changes nothing', () => {
    const base = { interestMethodology: 'flat', amortizationMethod: undefined, principal: 1000, loanTerm: 3 };

    assert.deepEqual(
      generateSchedule(loan({ ...base, principalThresholdForLastInstallment: 300 })),
      generateSchedule(loan(base)),
    );
  });
});

describe('deliberate differences from Zung', () => {
  test('weekly repayments with an annual rate use 52 weeks, not the daily rate', () => {
    const rate = periodInterestRate({ interestRate: 12, interestRateType: 'year', repaymentFrequencyType: 'weeks' });

    assert.equal(rate, 12 / 52 * 1 / 100);
    assert.notEqual(rate, 12 / 365 / 100); // what Zung computes

    const s = generateSchedule(loan({
      interestMethodology: 'flat', amortizationMethod: undefined, principal: 10000,
      repaymentFrequencyType: 'weeks', loanTerm: 52, interestRate: 12, interestRateType: 'year',
    }));
    assert.equal(s.installments[0].interest, 23.08);
    assert.equal(s.totalInterest, 1200.16); // ~12% of 10,000 over a year (23.08 x 52)
  });

  test('a fixed installment that can\'t pay the loan off is an error, not silently ignored', () => {
    assert.throws(() => generateSchedule(loan({ fixedInstallmentAmount: 200 })), InvalidLoanError);
  });

  test('0% equal installments split the principal evenly instead of dividing by zero', () => {
    const s = generateSchedule(loan({ interestRate: 0 }));

    assert.ok(s.installments.every((row) => row.principal === 1000 && row.interest === 0));
    assert.equal(s.totalRepayable, 12000);
  });
});

describe('periodInterestRate()', () => {
  const cases = [
    [{ interestRate: 18, interestRateType: 'year', repaymentFrequencyType: 'months' }, 0.015],
    [{ interestRate: 1.5, interestRateType: 'month', repaymentFrequencyType: 'months', repaymentFrequency: 3 }, 0.045],
    [{ interestRate: 36.5, interestRateType: 'year', repaymentFrequencyType: 'days', daysInYear: 365 }, 0.001],
    [{ interestRate: 3, interestRateType: 'month', repaymentFrequencyType: 'days', daysInMonth: 30 }, 0.001],
    [{ interestRate: 1, interestRateType: 'week', repaymentFrequencyType: 'months' }, 0.04],
    [{ interestRate: 2, interestRateType: 'month', repaymentFrequencyType: 'years' }, 0.24],
    [{ interestRate: 0.1, interestRateType: 'day', repaymentFrequencyType: 'weeks' }, 0.007],
  ];
  for (const [input, expected] of cases) {
    test(`${input.interestRate}% per ${input.interestRateType}, every ${input.repaymentFrequency ?? 1} ${input.repaymentFrequencyType}`, () => {
      assert.ok(Math.abs(periodInterestRate(input) - expected) < 1e-15);
    });
  }
});

describe('roundMoney()', () => {
  const cases = [[1.005, 2, 1.01], [2.675, 2, 2.68], [0.285, 2, 0.29], [-0.125, 2, -0.13], [2.5, 0, 3], [-2.5, 0, -3], [1234.5678, 3, 1234.568], [0.004, 2, 0], [-0.004, 2, 0]];
  for (const [value, decimals, expected] of cases) {
    test(`roundMoney(${value}, ${decimals}) === ${expected}`, () => {
      assert.equal(roundMoney(value, decimals), expected);
      assert.equal(Object.is(roundMoney(value, decimals), -0), false);
    });
  }
});

describe('validation', () => {
  const invalid = [
    ['unknown parameter', { interest: 5 }, /Unknown parameter/],
    ['zero principal', { principal: 0 }, /"principal"/],
    ['string principal', { principal: '1000' }, /"principal"/],
    ['NaN rate', { interestRate: Number.NaN }, /"interestRate"/],
    ['negative rate', { interestRate: -1 }, /"interestRate"/],
    ['fractional term', { loanTerm: 1.5 }, /"loanTerm"/],
    ['term not a multiple of frequency', { loanTerm: 10, repaymentFrequency: 3 }, /multiple/],
    ['unknown frequency type', { repaymentFrequencyType: 'fortnights' }, /"repaymentFrequencyType"/],
    ['singular frequency type', { repaymentFrequencyType: 'month' }, /"repaymentFrequencyType"/],
    ['plural rate type', { interestRateType: 'months' }, /"interestRateType"/],
    ['unknown methodology', { interestMethodology: 'compound' }, /"interestMethodology"/],
    ['declining without amortization', { amortizationMethod: undefined }, /"amortizationMethod"/],
    ['flat with amortization', { interestMethodology: 'flat', amortizationMethod: 'equal_installments' }, /only applies/],
    ['impossible date', { disbursementDate: '2026-02-30' }, /"disbursementDate"/],
    ['badly formatted date', { firstPaymentDate: '15/02/2026' }, /"firstPaymentDate"/],
    ['first payment before disbursement', { firstPaymentDate: '2026-01-14' }, /cannot be before/],
    ['negative grace', { graceOnInterestCharged: -1 }, /"graceOnInterestCharged"/],
    ['string exactDays flag', { exactDaysInFirstPeriod: 'yes' }, /"exactDaysInFirstPeriod"/],
    ['odd daysInYear', { daysInYear: 366 }, /"daysInYear"/],
    ['string daysInYear', { daysInYear: '360' }, /"daysInYear"/],
    ['odd daysInMonth', { daysInMonth: 28 }, /"daysInMonth"/],
    ['too many decimals', { decimalPlaces: 7 }, /"decimalPlaces"/],
    ['zero fixed installment', { fixedInstallmentAmount: 0 }, /"fixedInstallmentAmount"/],
    ['fixed installment not covering interest', { fixedInstallmentAmount: 240 }, /first period's interest \(240\)/],
    ['fixed installment taking over 10,000 installments', { interestRate: 0, fixedInstallmentAmount: 1 }, /too small: it would take 12000/],
    ['fixed installment on equal principal', { amortizationMethod: 'equal_principal_payments', fixedInstallmentAmount: 2000 }, /doesn't apply/],
    ['too many installments', { repaymentFrequencyType: 'days', interestRateType: 'day', interestRate: 0.1, loanTerm: 10_001 }, /limited to/],
  ];

  for (const [label, overrides, message] of invalid) {
    test(`rejects ${label}`, () => {
      assert.throws(() => generateSchedule(loan(overrides)), (error) => error instanceof InvalidLoanError && message.test(error.message));
    });
  }

  test('rejects a non-object', () => {
    assert.throws(() => generateSchedule(null), InvalidLoanError);
    assert.throws(() => generateSchedule([]), InvalidLoanError);
  });
});
