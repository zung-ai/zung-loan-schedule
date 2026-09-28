<?php

declare(strict_types=1);

namespace Zung\LoanSchedule;

/**
 * Thrown for any input the schedule generator can't use (missing or
 * out-of-range values, unknown options, impossible dates...). Nothing is
 * calculated when this is thrown.
 */
final class InvalidLoanException extends \InvalidArgumentException
{
}
