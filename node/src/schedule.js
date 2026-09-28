import { InvalidLoanError } from './errors.js';
import { roundMoney } from './money.js';
import {
  addDays, addMonthsNoOverflow, addYears, diffInDays, isLeapYear, monthLength, requireDate, yearOf,
} from './dates.js';

export const REPAYMENT_FREQUENCY_TYPES = Object.freeze(['days', 'weeks', 'months', 'years']);
export const INTEREST_RATE_TYPES = Object.freeze(['day', 'week', 'month', 'year']);
export const INTEREST_METHODOLOGIES = Object.freeze(['flat', 'declining_balance']);
export const AMORTIZATION_METHODS = Object.freeze(['equal_installments', 'equal_principal_payments']);

const MAX_INSTALLMENTS = 10_000;
const WEEKS_IN_YEAR = 52;
const WEEKS_IN_MONTH = 4;

const PARAMS = [
  'principal', 'loanTerm', 'repaymentFrequency', 'repaymentFrequencyType',
  'interestRate', 'interestRateType', 'interestMethodology', 'amortizationMethod',
  'disbursementDate', 'firstPaymentDate', 'graceOnInterestCharged',
  'interestCalculationPeriod', 'exactDaysInFirstPeriod', 'daysInYear', 'daysInMonth',
  'fixedInstallmentAmount', 'principalThresholdForLastInstallment', 'decimalPlaces',
];

/**
 * Converts a nominal rate (a percentage per `interestRateType`) into the
 * fraction charged per installment period.
 *
 * Conventions: a month is `daysInMonth` days or 4 weeks, a year is
 * `daysInYear` days, 52 weeks or 12 months.
 *
 * @param {object} options
 * @param {number} options.interestRate Percentage, e.g. 1.5 for 1.5%.
 * @param {'day'|'week'|'month'|'year'} options.interestRateType
 * @param {'days'|'weeks'|'months'|'years'} options.repaymentFrequencyType
 * @param {number} [options.repaymentFrequency=1]
 * @param {number} [options.daysInYear=365]
 * @param {number} [options.daysInMonth=30]
 * @returns {number} e.g. 0.015 for 1.5% per period.
 */
export function periodInterestRate({
  interestRate, interestRateType, repaymentFrequencyType, repaymentFrequency = 1, daysInYear = 365, daysInMonth = 30,
}) {
  let rate = interestRate;

  if (repaymentFrequencyType === 'days') {
    if (interestRateType === 'year') rate = rate / daysInYear;
    if (interestRateType === 'month') rate = rate / daysInMonth;
    if (interestRateType === 'week') rate = rate / 7;
  }
  if (repaymentFrequencyType === 'weeks') {
    // Zung's own engine divides by daysInYear here, charging a weekly loan
    // the daily rate (about 7x too little). This library uses 52 weeks.
    if (interestRateType === 'year') rate = rate / WEEKS_IN_YEAR;
    if (interestRateType === 'month') rate = rate / WEEKS_IN_MONTH;
    if (interestRateType === 'day') rate = rate * 7;
  }
  if (repaymentFrequencyType === 'months') {
    if (interestRateType === 'year') rate = rate / 12;
    if (interestRateType === 'week') rate = rate * WEEKS_IN_MONTH;
    if (interestRateType === 'day') rate = rate * daysInMonth;
  }
  if (repaymentFrequencyType === 'years') {
    if (interestRateType === 'month') rate = rate * 12;
    if (interestRateType === 'week') rate = rate * WEEKS_IN_YEAR;
    if (interestRateType === 'day') rate = rate * daysInYear;
  }

  return rate * repaymentFrequency / 100;
}

/**
 * Builds a loan's repayment schedule.
 *
 * @param {import('./index.js').LoanParams} params
 * @returns {import('./index.js').Schedule}
 */
export function generateSchedule(params) {
  const p = validate(params);
  const dp = p.decimalPlaces;
  const round = (value) => roundMoney(value, dp);

  const resolvedDaysInYear = p.daysInYear === 'actual'
    ? (isLeapYear(yearOf(p.disbursementDate)) ? 366 : 365)
    : p.daysInYear;
  const resolvedDaysInMonth = p.daysInMonth === 'actual' ? monthLength(p.disbursementDate) : p.daysInMonth;

  const rate = periodInterestRate({
    interestRate: p.interestRate,
    interestRateType: p.interestRateType,
    repaymentFrequencyType: p.repaymentFrequencyType,
    repaymentFrequency: p.repaymentFrequency,
    daysInYear: resolvedDaysInYear,
    daysInMonth: resolvedDaysInMonth,
  });

  let period = p.loanTerm / p.repaymentFrequency;
  if (p.fixedInstallmentAmount !== null) {
    // Zung silently falls back to loanTerm when the amount can't be solved
    // for; this library says so instead.
    const solved = solveInstallmentCount(p, p.fixedInstallmentAmount, p.principal, rate);
    if (solved === null) {
      throw new InvalidLoanError(
        `"fixedInstallmentAmount" must be more than the first period's interest (${roundMoney(p.principal * rate, p.decimalPlaces)}), or the loan never pays off.`,
      );
    }
    if (solved > MAX_INSTALLMENTS) {
      throw new InvalidLoanError(`"fixedInstallmentAmount" is too small: it would take ${solved} installments.`);
    }
    period = solved;
  }

  const useDaily = p.interestCalculationPeriod === 'daily' || p.exactDaysInFirstPeriod;
  const dailyRate = useDaily
    ? periodInterestRate({
      interestRate: p.interestRate,
      interestRateType: p.interestRateType,
      repaymentFrequencyType: 'days',
      daysInYear: resolvedDaysInYear,
      daysInMonth: resolvedDaysInMonth,
    })
    : null;

  const principal = p.principal;
  const equalInstallments = p.interestMethodology === 'declining_balance' && p.amortizationMethod === 'equal_installments';
  const amortizedPayment = equalInstallments ? round(amortizedPaymentFor(rate, principal, period)) : null;

  let balance = round(principal);
  let fromDate = p.disbursementDate;
  let dueDate = p.firstPaymentDate;
  let totalPrincipal = 0;
  let totalInterest = 0;
  const installments = [];

  for (let i = 1; i <= period; i++) {
    const daysInPeriod = diffInDays(fromDate, dueDate) + 1;
    const dailyApplies = dailyRate !== null
      && (p.interestCalculationPeriod === 'daily' || (i === 1 && p.exactDaysInFirstPeriod));

    let interest;
    let installmentPrincipal;

    if (p.interestMethodology === 'flat') {
      installmentPrincipal = round(principal / period);
      interest = dailyApplies ? round(dailyRate * daysInPeriod * principal) : round(rate * principal);
    } else if (equalInstallments) {
      // The daily/exact-day option doesn't apply to equal installments: the
      // payment is fixed from the period rate (same as Zung).
      interest = round(rate * balance);
      installmentPrincipal = round(amortizedPayment - interest);
    } else {
      installmentPrincipal = round(principal / period);
      interest = dailyApplies ? round(dailyRate * daysInPeriod * balance) : round(rate * balance);
    }

    const rowInterest = p.graceOnInterestCharged >= i ? 0 : interest;
    const rowPrincipal = i === period ? round(balance) : installmentPrincipal;
    balance = balance - installmentPrincipal;

    installments.push({
      installment: i,
      fromDate,
      dueDate,
      principal: rowPrincipal,
      interest: rowInterest,
      totalDue: round(rowPrincipal + rowInterest),
      balance: 0, // filled in below, after any final-installment merge
    });
    totalPrincipal += rowPrincipal;
    totalInterest += rowInterest;

    fromDate = addDays(dueDate, 1);
    dueDate = nextDueDate(dueDate, p.repaymentFrequency, p.repaymentFrequencyType);
  }

  const threshold = p.principalThresholdForLastInstallment;
  if (threshold !== null && threshold > 0 && installments.length > 1) {
    const last = installments.at(-1);
    if (last.principal < threshold) {
      const secondLast = installments.at(-2);
      secondLast.principal = round(secondLast.principal + last.principal);
      secondLast.interest = round(secondLast.interest + last.interest);
      secondLast.totalDue = round(secondLast.principal + secondLast.interest);
      installments.pop();
    }
  }

  let outstanding = round(principal);
  for (const row of installments) {
    outstanding = round(outstanding - row.principal);
    row.balance = outstanding;
  }

  return {
    installments,
    totalPrincipal: round(totalPrincipal),
    totalInterest: round(totalInterest),
    totalRepayable: round(totalPrincipal + totalInterest),
    periodInterestRate: rate,
    firstPaymentDate: installments[0].dueDate,
    maturityDate: installments.at(-1).dueDate,
  };
}

function nextDueDate(date, frequency, type) {
  if (type === 'months') return addMonthsNoOverflow(date, frequency);
  if (type === 'years') return addYears(date, frequency);
  if (type === 'weeks') return addDays(date, 7 * frequency);
  return addDays(date, frequency);
}

/**
 * The classic EMI formula. At 0% it is the limit of the formula: an even
 * split of the principal (Zung's own engine divides by zero here).
 */
function amortizedPaymentFor(rate, balance, period) {
  if (rate === 0) {
    return balance / period;
  }

  return (rate * balance * (1 + rate) ** period) / ((1 + rate) ** period - 1);
}

/**
 * How many installments a fixed payment amount pays the loan off in, or
 * null when that isn't defined (equal principal payments, or a payment
 * that doesn't even cover the first period's interest).
 */
function solveInstallmentCount(p, amount, principal, rate) {
  const firstPeriodInterest = principal * rate;

  if (p.interestMethodology === 'flat') {
    if (amount <= firstPeriodInterest) return null;
    return Math.ceil(principal / (amount - firstPeriodInterest));
  }

  if (p.amortizationMethod === 'equal_installments') {
    if (rate <= 0) return Math.ceil(principal / amount);
    if (amount <= firstPeriodInterest) return null;
    const x = amount / (amount - firstPeriodInterest);
    return Math.ceil(Math.log(x) / Math.log(1 + rate));
  }

  return null;
}

function validate(params) {
  if (params === null || typeof params !== 'object' || Array.isArray(params)) {
    throw new InvalidLoanError('Loan parameters must be an object.');
  }

  const unknown = Object.keys(params).filter((key) => !PARAMS.includes(key));
  if (unknown.length > 0) {
    throw new InvalidLoanError(`Unknown parameter(s): ${unknown.join(', ')}. Allowed: ${PARAMS.join(', ')}.`);
  }

  const p = {
    principal: requireNumber(params.principal, 'principal', { min: 0, exclusive: true }),
    loanTerm: requireInt(params.loanTerm, 'loanTerm', 1),
    repaymentFrequency: requireInt(params.repaymentFrequency ?? 1, 'repaymentFrequency', 1),
    repaymentFrequencyType: requireOneOf(params.repaymentFrequencyType, 'repaymentFrequencyType', REPAYMENT_FREQUENCY_TYPES),
    interestRate: requireNumber(params.interestRate, 'interestRate', { min: 0 }),
    interestRateType: requireOneOf(params.interestRateType, 'interestRateType', INTEREST_RATE_TYPES),
    interestMethodology: requireOneOf(params.interestMethodology, 'interestMethodology', INTEREST_METHODOLOGIES),
    amortizationMethod: null,
    disbursementDate: requireDate(params.disbursementDate, 'disbursementDate'),
    firstPaymentDate: requireDate(params.firstPaymentDate, 'firstPaymentDate'),
    graceOnInterestCharged: requireInt(params.graceOnInterestCharged ?? 0, 'graceOnInterestCharged', 0),
    interestCalculationPeriod: requireOneOf(params.interestCalculationPeriod ?? 'same', 'interestCalculationPeriod', ['same', 'daily']),
    exactDaysInFirstPeriod: params.exactDaysInFirstPeriod ?? false,
    daysInYear: params.daysInYear ?? 'actual',
    daysInMonth: params.daysInMonth ?? 'actual',
    fixedInstallmentAmount: params.fixedInstallmentAmount ?? null,
    principalThresholdForLastInstallment: params.principalThresholdForLastInstallment ?? null,
    decimalPlaces: requireInt(params.decimalPlaces ?? 2, 'decimalPlaces', 0),
  };

  if (p.interestMethodology === 'declining_balance') {
    p.amortizationMethod = requireOneOf(params.amortizationMethod, 'amortizationMethod', AMORTIZATION_METHODS);
  } else if (params.amortizationMethod !== undefined && params.amortizationMethod !== null) {
    throw new InvalidLoanError('"amortizationMethod" only applies to declining_balance loans.');
  }

  if (p.loanTerm % p.repaymentFrequency !== 0) {
    throw new InvalidLoanError('"loanTerm" must be a whole multiple of "repaymentFrequency".');
  }
  if (p.loanTerm / p.repaymentFrequency > MAX_INSTALLMENTS) {
    throw new InvalidLoanError(`A schedule is limited to ${MAX_INSTALLMENTS} installments.`);
  }
  if (p.firstPaymentDate < p.disbursementDate) {
    throw new InvalidLoanError('"firstPaymentDate" cannot be before "disbursementDate".');
  }
  if (typeof p.exactDaysInFirstPeriod !== 'boolean') {
    throw new InvalidLoanError('"exactDaysInFirstPeriod" must be a boolean.');
  }
  if (!['actual', 360, 364, 365].includes(p.daysInYear)) {
    throw new InvalidLoanError('"daysInYear" must be "actual", 360, 364 or 365.');
  }
  if (!['actual', 30, 31].includes(p.daysInMonth)) {
    throw new InvalidLoanError('"daysInMonth" must be "actual", 30 or 31.');
  }
  if (p.decimalPlaces > 6) {
    throw new InvalidLoanError('"decimalPlaces" must be between 0 and 6.');
  }
  if (p.fixedInstallmentAmount !== null) {
    requireNumber(p.fixedInstallmentAmount, 'fixedInstallmentAmount', { min: 0, exclusive: true });
    if (p.amortizationMethod === 'equal_principal_payments') {
      throw new InvalidLoanError('"fixedInstallmentAmount" doesn\'t apply to equal_principal_payments, whose installments vary by design.');
    }
  }
  if (p.principalThresholdForLastInstallment !== null) {
    requireNumber(p.principalThresholdForLastInstallment, 'principalThresholdForLastInstallment', { min: 0 });
  }

  return p;
}

function requireNumber(value, name, { min, exclusive = false }) {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new InvalidLoanError(`"${name}" is required and must be a finite number.`);
  }
  if (exclusive ? value <= min : value < min) {
    throw new InvalidLoanError(`"${name}" must be ${exclusive ? 'greater than' : 'at least'} ${min}.`);
  }
  return value;
}

function requireInt(value, name, min) {
  if (!Number.isSafeInteger(value) || value < min) {
    throw new InvalidLoanError(`"${name}" must be an integer of at least ${min}.`);
  }
  return value;
}

function requireOneOf(value, name, allowed) {
  if (!allowed.includes(value)) {
    throw new InvalidLoanError(`"${name}" must be one of: ${allowed.join(', ')}.`);
  }
  return value;
}
