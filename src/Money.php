<?php

declare(strict_types=1);

namespace Beexar;

/**
 * A decimal amount in a currency's main unit, never a float.
 *
 * Every amount and balance on this contract is a decimal STRING in the
 * currency's main unit: "0.90" is ninety cents, not ninety. This class holds an
 * integer number of units plus a scale and does its arithmetic on digit strings,
 * so it needs no extension — no bcmath, no gmp — and never loses a digit.
 *
 * There is deliberately no way to build a Money from a float. IEEE-754 cannot
 * hold 0.1, and a wallet that rounds a player's balance by a hundredth of a cent
 * per round is a reconciliation incident, not a rounding detail.
 */
final class Money implements \JsonSerializable, \Stringable
{
    /** Upper bound on fractional digits, mirroring NUMERIC(38,16) storage. */
    public const MAX_SCALE = 16;

    /** Length cap on an untrusted decimal string, mirroring `maxLength: 40`. */
    public const MAX_CLIENT_DECIMAL_LEN = 40;

    /**
     * The shape the contract accepts: a non-negative plain decimal, at most
     * MAX_SCALE fractional digits, and NO scientific notation.
     *
     * Rejecting the exponent form is the load-bearing part. "1E2000000000"
     * parses in microseconds in every bignum library and costs nothing until the
     * first rescale, at which point it asks for a multi-gigabyte integer and
     * takes the process with it. The guard has to run on shape, before any
     * arithmetic touches the value.
     */
    private const CLIENT_DECIMAL = '/^\d+(\.\d{1,16})?$/';

    /** @param string $digits unsigned digit string, no leading sign */
    private function __construct(
        private readonly string $digits,
        private readonly bool $negative,
        private readonly int $scale,
    ) {
    }

    /** Zero at scale 2 — the scale the contract's examples use. */
    public static function zero(): self
    {
        return new self('0', false, 2);
    }

    /** Parses a decimal string from the wire. */
    public static function parse(string $value): self
    {
        $parsed = self::tryParse($value);
        if ($parsed === null) {
            throw new MoneyException(sprintf('not a valid decimal amount: %s', var_export($value, true)));
        }

        return $parsed;
    }

    /** Like parse() but returns null instead of throwing. */
    public static function tryParse(mixed $value): ?self
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > self::MAX_CLIENT_DECIMAL_LEN) {
            return null;
        }
        if (preg_match(self::CLIENT_DECIMAL, $value) !== 1) {
            return null;
        }

        $dot = strpos($value, '.');
        if ($dot === false) {
            return new self(self::trimZeros($value), false, 0);
        }

        $digits = substr($value, 0, $dot) . substr($value, $dot + 1);

        return new self(self::trimZeros($digits), false, \strlen($value) - $dot - 1);
    }

    /**
     * Builds an amount from an integer number of minor units — the bridge for a
     * ledger that stores cents. fromMinorUnits('9970', 2) is "99.70".
     *
     * Takes a string rather than an int so a value past PHP_INT_MAX cannot
     * silently lose its low digits on a 32-bit build.
     */
    public static function fromMinorUnits(string $units, int $scale): self
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new MoneyException(sprintf('scale must be in [0, %d], got %d', self::MAX_SCALE, $scale));
        }
        $negative = str_starts_with($units, '-');
        $digits = $negative ? substr($units, 1) : $units;
        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            throw new MoneyException(sprintf('minor units must be an integer string, got %s', var_export($units, true)));
        }

        return new self(self::trimZeros($digits), $negative && self::trimZeros($digits) !== '0', $scale);
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /** The unscaled integer value at scale(), signed. */
    public function minorUnits(): string
    {
        return ($this->negative ? '-' : '') . $this->digits;
    }

    /**
     * Renders with exactly $scale fractional digits, truncating toward zero —
     * the same direction the platform truncates, so a rounding step can never
     * inflate what the operator owes.
     */
    public function format(int $scale): string
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new MoneyException(sprintf('scale must be in [0, %d], got %d', self::MAX_SCALE, $scale));
        }
        $digits = self::rescaleDigits($this->digits, $this->scale, $scale);
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        $int = substr($digits, 0, \strlen($digits) - $scale);
        $frac = $scale === 0 ? '' : '.' . substr($digits, \strlen($digits) - $scale);
        $sign = ($this->negative && self::trimZeros($digits) !== '0') ? '-' : '';

        return $sign . $int . $frac;
    }

    /**
     * Renders bonus_amount, which the contract caps at 12 fractional digits
     * (`^\d{1,18}(\.\d{1,12})?$`) while platform money allows 16. Clamping keeps
     * a 16-decimal currency from emitting a value the contract would reject.
     */
    public function formatBonus(): string
    {
        return $this->format(min($this->scale, 12));
    }

    public function __toString(): string
    {
        return $this->format($this->scale);
    }

    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    public function add(self $other): self
    {
        return $this->combine($other, false);
    }

    public function sub(self $other): self
    {
        return $this->combine($other, true);
    }

    /** -1, 0 or 1. */
    public function compare(self $other): int
    {
        $scale = max($this->scale, $other->scale);
        $a = self::rescaleDigits($this->digits, $this->scale, $scale);
        $b = self::rescaleDigits($other->digits, $other->scale, $scale);

        if ($this->isZeroDigits($a) && $this->isZeroDigits($b)) {
            return 0;
        }
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }
        $cmp = self::compareDigits($a, $b);

        return $this->negative ? -$cmp : $cmp;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->isZeroDigits($this->digits);
    }

    public function isNegative(): bool
    {
        return $this->negative && !$this->isZero();
    }

    public function isPositive(): bool
    {
        return !$this->negative && !$this->isZero();
    }

    /** Returns this, or zero when this is negative. */
    public function clampToZero(): self
    {
        return $this->isNegative() ? new self('0', false, $this->scale) : $this;
    }

    // --- digit-string arithmetic -------------------------------------------

    private function combine(self $other, bool $subtract): self
    {
        $scale = max($this->scale, $other->scale);
        $a = self::rescaleDigits($this->digits, $this->scale, $scale);
        $b = self::rescaleDigits($other->digits, $other->scale, $scale);
        $aNeg = $this->negative;
        $bNeg = $subtract ? !$other->negative : $other->negative;

        if ($aNeg === $bNeg) {
            return new self(self::addDigits($a, $b), $aNeg, $scale);
        }

        $cmp = self::compareDigits($a, $b);
        if ($cmp === 0) {
            return new self('0', false, $scale);
        }
        if ($cmp > 0) {
            return new self(self::subDigits($a, $b), $aNeg, $scale);
        }

        return new self(self::subDigits($b, $a), $bNeg, $scale);
    }

    private function isZeroDigits(string $digits): bool
    {
        return self::trimZeros($digits) === '0';
    }

    private static function trimZeros(string $digits): string
    {
        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function rescaleDigits(string $digits, int $from, int $to): string
    {
        if ($to === $from) {
            return $digits;
        }
        if ($to > $from) {
            return $digits . str_repeat('0', $to - $from);
        }
        // Truncate toward zero.
        $keep = \strlen($digits) - ($from - $to);

        return $keep <= 0 ? '0' : substr($digits, 0, $keep);
    }

    private static function compareDigits(string $a, string $b): int
    {
        $a = self::trimZeros($a);
        $b = self::trimZeros($b);
        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    private static function addDigits(string $a, string $b): string
    {
        $len = max(\strlen($a), \strlen($b));
        $a = str_pad($a, $len, '0', STR_PAD_LEFT);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);

        $out = '';
        $carry = 0;
        for ($i = $len - 1; $i >= 0; --$i) {
            $sum = (int) $a[$i] + (int) $b[$i] + $carry;
            $out = (string) ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }

        return $carry > 0 ? (string) $carry . $out : $out;
    }

    /** Requires $a >= $b. */
    private static function subDigits(string $a, string $b): string
    {
        $len = max(\strlen($a), \strlen($b));
        $a = str_pad($a, $len, '0', STR_PAD_LEFT);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);

        $out = '';
        $borrow = 0;
        for ($i = $len - 1; $i >= 0; --$i) {
            $diff = (int) $a[$i] - (int) $b[$i] - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out = (string) $diff . $out;
        }

        return self::trimZeros($out);
    }
}
