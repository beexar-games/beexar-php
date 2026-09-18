<?php

declare(strict_types=1);

namespace Beexar;

/**
 * Currency codes on this contract: ISO-4217 fiat, a crypto ticker, or an
 * operator's custom code prefixed CUSTOM_.
 *
 * Lowercase is REJECTED, never normalised — the platform's own schema does the
 * same, and quietly upcasing here would hide an operator bug until settlement.
 */
final class Currency
{
    public const PATTERN = '/^[A-Z][A-Z0-9_]{2,31}$/';
    public const PATTERN_SOURCE = '^[A-Z][A-Z0-9_]{2,31}$';

    public static function isValid(mixed $code): bool
    {
        return \is_string($code) && preg_match(self::PATTERN, $code) === 1;
    }
}
