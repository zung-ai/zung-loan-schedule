/**
 * Round half away from zero to `decimals` places, bit-for-bit the same as
 * PHP 8.4's round() (the rounding Zung's own schedule engine uses), so a
 * schedule from this library matches Zung's to the cent, including every
 * half-cent tie. The PHP implementation of this library ports the same
 * algorithm, so it doesn't depend on which PHP version runs it.
 *
 * Ported from php-src ext/standard/math.c (_php_math_round with
 * PHP_ROUND_HALF_UP, places >= 0):
 *  1. scale and truncate toward zero;
 *  2. if the truncated value plus one scales back to exactly the input, the
 *     scaling fell one unit short (0.285 * 100 = 28.499...), so use that;
 *  3. round away from zero when |value| reaches the half-way point computed
 *     back in the original scale.
 *
 * roundMoney(1.005, 2) === 1.01, roundMoney(2.675, 2) === 2.68.
 *
 * @param {number} value
 * @param {number} decimals 0 to 22.
 * @returns {number}
 */
export function roundMoney(value, decimals) {
  if (!Number.isFinite(value) || value === 0) {
    return value === 0 ? 0 : value;
  }
  if (decimals === 0 && value === Math.trunc(value)) {
    return value;
  }

  const exponent = 10 ** decimals;

  let integral;
  let next;
  if (value >= 0) {
    integral = Math.floor(value * exponent);
    next = integral + 1;
  } else {
    integral = Math.ceil(value * exponent);
    next = integral - 1;
  }

  if (next / exponent === value) {
    integral = next;
  }

  // Beyond double precision: rounding is meaningless.
  if (Math.abs(integral) >= 1e16) {
    return value;
  }

  const edge = Math.abs((integral + copySign(0.5, integral)) / exponent);
  if (Math.abs(value) >= edge) {
    integral += copySign(1, integral);
  }

  const result = integral / exponent;

  return result === 0 ? 0 : result; // never -0
}

/** C's copysign(): the magnitude of `x` with the sign bit of `y` (-0 counts as negative). */
function copySign(x, y) {
  return y < 0 || Object.is(y, -0) ? -Math.abs(x) : Math.abs(x);
}
