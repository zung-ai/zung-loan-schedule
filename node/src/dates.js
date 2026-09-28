// Calendar arithmetic on plain `YYYY-MM-DD` strings. No time zones are
// involved anywhere: every value is a calendar date, handled in UTC.

import { InvalidLoanError } from './errors.js';

const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const DAY_MS = 86_400_000;

/**
 * @param {unknown} value
 * @param {string} name
 * @returns {string}
 */
export function requireDate(value, name) {
  const match = typeof value === 'string' ? DATE_PATTERN.exec(value) : null;
  if (match) {
    const [, y, m, d] = match.map(Number);
    if (m >= 1 && m <= 12 && d >= 1 && d <= daysInMonth(y, m)) {
      return value;
    }
  }

  throw new InvalidLoanError(`"${name}" must be a real calendar date in YYYY-MM-DD format.`);
}

export function isLeapYear(year) {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

export function daysInMonth(year, month) {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function parts(date) {
  return date.split('-').map(Number);
}

function toTime(date) {
  const [y, m, d] = parts(date);
  return Date.UTC(y, m - 1, d);
}

function fromTime(time) {
  return new Date(time).toISOString().slice(0, 10);
}

function format(y, m, d) {
  return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

export function yearOf(date) {
  return parts(date)[0];
}

export function monthLength(date) {
  const [y, m] = parts(date);
  return daysInMonth(y, m);
}

export function addDays(date, days) {
  return fromTime(toTime(date) + days * DAY_MS);
}

/**
 * Whole days from `from` to `to` (negative if `to` is earlier).
 */
export function diffInDays(from, to) {
  return Math.round((toTime(to) - toTime(from)) / DAY_MS);
}

/**
 * Adds months, clamping to the end of a shorter month instead of spilling
 * into the next one: Jan 31 + 1 month = Feb 28 (or 29).
 */
export function addMonthsNoOverflow(date, months) {
  const [y, m, d] = parts(date);
  const total = y * 12 + (m - 1) + months;
  const year = Math.floor(total / 12);
  const month = (total % 12) + 1;

  return format(year, month, Math.min(d, daysInMonth(year, month)));
}

/**
 * Adds years, letting Feb 29 spill over to Mar 1 in a non-leap year (the
 * same as Carbon's default, which Zung uses).
 */
export function addYears(date, years) {
  const [y, m, d] = parts(date);
  const year = y + years;

  if (d <= daysInMonth(year, m)) {
    return format(year, m, d);
  }

  return addDays(format(year, m, daysInMonth(year, m)), d - daysInMonth(year, m));
}
