/**
 * @typedef {object} LoanParams
 * @property {number} principal
 * @property {number} loanTerm Length of the loan in `repaymentFrequencyType` units.
 * @property {number} [repaymentFrequency=1] Pay every N units.
 * @property {'days'|'weeks'|'months'|'years'} repaymentFrequencyType
 * @property {number} interestRate Percentage per `interestRateType`, e.g. 1.5.
 * @property {'day'|'week'|'month'|'year'} interestRateType
 * @property {'flat'|'declining_balance'} interestMethodology
 * @property {'equal_installments'|'equal_principal_payments'} [amortizationMethod] Required for declining_balance.
 * @property {string} disbursementDate YYYY-MM-DD
 * @property {string} firstPaymentDate YYYY-MM-DD
 * @property {number} [graceOnInterestCharged=0] Number of leading installments charged no interest.
 * @property {'same'|'daily'} [interestCalculationPeriod='same']
 * @property {boolean} [exactDaysInFirstPeriod=false]
 * @property {'actual'|360|364|365} [daysInYear='actual']
 * @property {'actual'|30|31} [daysInMonth='actual']
 * @property {number|null} [fixedInstallmentAmount]
 * @property {number|null} [principalThresholdForLastInstallment]
 * @property {number} [decimalPlaces=2]
 */

/**
 * @typedef {object} Installment
 * @property {number} installment
 * @property {string} fromDate
 * @property {string} dueDate
 * @property {number} principal
 * @property {number} interest
 * @property {number} totalDue
 * @property {number} balance Principal still outstanding after this installment.
 */

/**
 * @typedef {object} Schedule
 * @property {Installment[]} installments
 * @property {number} totalPrincipal
 * @property {number} totalInterest
 * @property {number} totalRepayable
 * @property {number} periodInterestRate
 * @property {string} firstPaymentDate
 * @property {string} maturityDate Due date of the last installment.
 */

export {
  generateSchedule,
  periodInterestRate,
  REPAYMENT_FREQUENCY_TYPES,
  INTEREST_RATE_TYPES,
  INTEREST_METHODOLOGIES,
  AMORTIZATION_METHODS,
} from './schedule.js';
export { roundMoney } from './money.js';
export { InvalidLoanError } from './errors.js';

export const VERSION = '0.1.0';
