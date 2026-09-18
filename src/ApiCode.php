<?php

declare(strict_types=1);

namespace Beexar;

/**
 * SoftSwiss API codes, ported verbatim from the platform's own registry.
 *
 * Two things about this contract surprise everyone once:
 *
 *  1. There are only two HTTP statuses (400 and 500) and two `code` values
 *     ("invalid_argument" and "internal"). All meaning lives in
 *     `meta.api_code`. Branch on that, never on the status.
 *  2. A signature failure is HTTP 400 with api_code "403", not HTTP 403.
 *     Answering 403 breaks the contract.
 */
final class ApiCode
{
    public const INSUFFICIENT_FUNDS = '100';
    public const INVALID_PLAYER = '101';
    public const BET_LIMIT_REACHED = '105';
    public const MAX_BET_EXCEEDED = '106';
    public const GAME_FORBIDDEN = '107';
    public const PLAYER_DISABLED = '110';
    public const COUNTRY_RESTRICTED = '153';
    public const CURRENCY_NOT_ALLOWED = '154';
    public const FIELD_IMMUTABLE = '155';

    public const BAD_REQUEST = '400';
    public const FORBIDDEN = '403';
    public const NOT_FOUND = '404';

    /**
     * Beexar extension. Return it from /betwin to reject a transaction whose
     * id_provider was already rolled back — including a rollback that arrived
     * BEFORE the bet it reverses.
     */
    public const ALREADY_ROLLED_BACK = '409';

    public const GAME_NOT_AVAILABLE = '405';
    public const CASINO_DISABLED = '410';

    public const UNKNOWN_ERROR = '500';
    public const SERVICE_UNAVAILABLE = '503';
    public const REQUEST_TIMEOUT = '504';

    public const TWIRP_INVALID_ARGUMENT = 'invalid_argument';
    public const TWIRP_INTERNAL = 'internal';

    /**
     * Codes that MUST carry the player's balance in meta.balance.
     *
     * The platform will not complain if you omit it — it reads the field with
     * the error swallowed — so the player simply sees a wrong balance. That is
     * exactly why this SDK refuses to build such an error without one.
     */
    public static function isFundsRelated(string $code): bool
    {
        return $code === self::INSUFFICIENT_FUNDS
            || $code === self::BET_LIMIT_REACHED
            || $code === self::MAX_BET_EXCEEDED;
    }
}
