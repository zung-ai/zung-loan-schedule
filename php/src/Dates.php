<?php

declare(strict_types=1);

namespace Zung\LoanSchedule;

/**
 * Calendar arithmetic on plain Y-m-d strings. No time zones are involved:
 * every value is a calendar date.
 *
 * @internal
 */
final class Dates
{
    public static function require(mixed $value, string $name): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        throw new InvalidLoanException(sprintf('"%s" must be a real calendar date in YYYY-MM-DD format.', $name));
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    public static function year(string $date): int
    {
        return (int) substr($date, 0, 4);
    }

    public static function monthLength(string $date): int
    {
        return self::daysInMonth((int) substr($date, 0, 4), (int) substr($date, 5, 2));
    }

    public static function addDays(string $date, int $days): string
    {
        return self::fromDayNumber(self::dayNumber($date) + $days);
    }

    /**
     * Whole days from $from to $to (negative if $to is earlier).
     */
    public static function diffInDays(string $from, string $to): int
    {
        return self::dayNumber($to) - self::dayNumber($from);
    }

    /**
     * Adds months, clamping to the end of a shorter month instead of
     * spilling into the next one: Jan 31 + 1 month = Feb 28 (or 29).
     */
    public static function addMonthsNoOverflow(string $date, int $months): string
    {
        [$y, $m, $d] = self::parts($date);
        $total = $y * 12 + ($m - 1) + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;

        return self::format($year, $month, min($d, self::daysInMonth($year, $month)));
    }

    /**
     * Adds years, letting Feb 29 spill over to Mar 1 in a non-leap year (the
     * same as Carbon's default, which Zung uses).
     */
    public static function addYears(string $date, int $years): string
    {
        [$y, $m, $d] = self::parts($date);
        $year = $y + $years;
        $length = self::daysInMonth($year, $m);

        if ($d <= $length) {
            return self::format($year, $m, $d);
        }

        return self::addDays(self::format($year, $m, $length), $d - $length);
    }

    /**
     * @return array{int, int, int}
     */
    private static function parts(string $date): array
    {
        return [(int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2)];
    }

    private static function format(int $y, int $m, int $d): string
    {
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Days since 0000-03-01 in the proleptic Gregorian calendar.
     */
    private static function dayNumber(string $date): int
    {
        [$y, $m, $d] = self::parts($date);
        if ($m <= 2) {
            $y--;
            $m += 12;
        }

        return 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) + intdiv(153 * ($m - 3) + 2, 5) + $d - 1;
    }

    private static function fromDayNumber(int $n): string
    {
        $y = intdiv(10000 * $n + 14780, 3652425);
        $doy = $n - (365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400));
        if ($doy < 0) {
            $y--;
            $doy = $n - (365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400));
        }
        $mi = intdiv(100 * $doy + 52, 3060);
        $month = ($mi + 2) % 12 + 1;
        $year = $y + intdiv($mi + 2, 12);
        $day = $doy - intdiv($mi * 306 + 5, 10) + 1;

        return self::format($year, $month, $day);
    }
}
