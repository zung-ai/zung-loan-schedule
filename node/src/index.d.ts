export const VERSION: string;

export type RepaymentFrequencyType = 'days' | 'weeks' | 'months' | 'years';
export type InterestRateType = 'day' | 'week' | 'month' | 'year';
export type InterestMethodology = 'flat' | 'declining_balance';
export type AmortizationMethod = 'equal_installments' | 'equal_principal_payments';

export const REPAYMENT_FREQUENCY_TYPES: readonly RepaymentFrequencyType[];
export const INTEREST_RATE_TYPES: readonly InterestRateType[];
export const INTEREST_METHODOLOGIES: readonly InterestMethodology[];
export const AMORTIZATION_METHODS: readonly AmortizationMethod[];

interface CommonLoanParams {
  principal: number;
  /** Length of the loan in `repaymentFrequencyType` units. Must be a multiple of `repaymentFrequency`. */
  loanTerm: number;
  /** Pay every N units. Defaults to 1. */
  repaymentFrequency?: number;
  repaymentFrequencyType: RepaymentFrequencyType;
  /** Percentage per `interestRateType`, e.g. 1.5 for 1.5%. */
  interestRate: number;
  interestRateType: InterestRateType;
  /** `YYYY-MM-DD` */
  disbursementDate: string;
  /** `YYYY-MM-DD`, on or after `disbursementDate`. */
  firstPaymentDate: string;
  /** Number of leading installments charged no interest. Defaults to 0. */
  graceOnInterestCharged?: number;
  /** `'daily'` charges interest per calendar day of each period. Defaults to `'same'`. */
  interestCalculationPeriod?: 'same' | 'daily';
  /** Charge the first installment's interest by its exact number of days. */
  exactDaysInFirstPeriod?: boolean;
  daysInYear?: 'actual' | 360 | 364 | 365;
  daysInMonth?: 'actual' | 30 | 31;
  /** Solve for the number of installments this payment amount takes instead of using `loanTerm`. */
  fixedInstallmentAmount?: number | null;
  /** Merge a final installment whose principal is below this into the one before it. */
  principalThresholdForLastInstallment?: number | null;
  /** Defaults to 2. */
  decimalPlaces?: number;
}

export type LoanParams =
  | (CommonLoanParams & { interestMethodology: 'flat'; amortizationMethod?: null })
  | (CommonLoanParams & { interestMethodology: 'declining_balance'; amortizationMethod: AmortizationMethod });

export interface Installment {
  installment: number;
  fromDate: string;
  dueDate: string;
  principal: number;
  interest: number;
  totalDue: number;
  /** Principal still outstanding after this installment. */
  balance: number;
}

export interface Schedule {
  installments: Installment[];
  totalPrincipal: number;
  totalInterest: number;
  totalRepayable: number;
  /** Fraction charged per installment period, e.g. 0.015. */
  periodInterestRate: number;
  firstPaymentDate: string;
  /** Due date of the last installment. */
  maturityDate: string;
}

export function generateSchedule(params: LoanParams): Schedule;

export function periodInterestRate(options: {
  interestRate: number;
  interestRateType: InterestRateType;
  repaymentFrequencyType: RepaymentFrequencyType;
  repaymentFrequency?: number;
  daysInYear?: number;
  daysInMonth?: number;
}): number;

/** Round half away from zero, e.g. `roundMoney(1.005, 2) === 1.01`. */
export function roundMoney(value: number, decimals: number): number;

export class InvalidLoanError extends Error {}
