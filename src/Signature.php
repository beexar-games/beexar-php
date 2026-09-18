<?php

declare(strict_types=1);

namespace Beexar;

/**
 * X-REQUEST-SIGN — hex-encoded HMAC-SHA256 of the RAW request body, keyed with
 * the operator's API secret. 64 lowercase hex characters.
 */
final class Signature
{
    public const HEADER = 'X-REQUEST-SIGN';

    /** Signs the exact bytes you are about to put on the wire. */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * Constant-time signature check.
     *
     * $body must be the bytes as received. If anything between the socket and
     * this call parsed the JSON and serialised it again, those bytes are gone
     * and no amount of care here can recover them.
     */
    public static function verify(string $body, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($body, $secret), $signature);
    }
}
