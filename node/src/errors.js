/**
 * Thrown for any input the schedule generator can't use (missing or
 * out-of-range values, unknown options, impossible dates...). Nothing is
 * calculated when this is thrown.
 */
export class InvalidLoanError extends Error {
  constructor(message) {
    super(message);
    this.name = 'InvalidLoanError';
    if (Error.captureStackTrace) {
      Error.captureStackTrace(this, InvalidLoanError);
    }
  }
}
