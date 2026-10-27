<?php

declare(strict_types=1);

namespace Beexar;

/**
 * The exception your handler throws to answer the platform with a specific
 * api_code. Anything else you throw becomes an opaque 500 — your message is
 * never echoed to the platform.
 */
final class WalletError extends \RuntimeException
{
    private function __construct(
        public readonly int $status,
        public readonly string $twirpCode,
        public readonly string $apiCode,
        string $message,
        public readonly ?Money $balance = null,
    ) {
        parent::__construct($message);

        if (ApiCode::isFundsRelated($apiCode) && $balance === null) {
            throw new \InvalidArgumentException(sprintf(
                'api_code %s must carry the player\'s balance; use insufficientFunds(), '
                . 'betLimitReached() or maxBetExceeded()',
                $apiCode,
            ));
        }
    }

    private static function client(string $apiCode, string $message, ?Money $balance = null): self
    {
        return new self(400, ApiCode::TWIRP_INVALID_ARGUMENT, $apiCode, $message, $balance);
    }

    private static function server(string $apiCode, string $message): self
    {
        return new self(500, ApiCode::TWIRP_INTERNAL, $apiCode, $message);
    }

    // --- funds-related: the balance is a required argument, by design ------

    public static function insufficientFunds(Money $balance, string $message = 'insufficient funds'): self
    {
        return self::client(ApiCode::INSUFFICIENT_FUNDS, $message, $balance);
    }

    public static function betLimitReached(Money $balance, string $message = 'bet limit reached'): self
    {
        return self::client(ApiCode::BET_LIMIT_REACHED, $message, $balance);
    }

    public static function maxBetExceeded(Money $balance, string $message = 'max bet exceeded'): self
    {
        return self::client(ApiCode::MAX_BET_EXCEEDED, $message, $balance);
    }

    // --- player / request --------------------------------------------------

    public static function invalidPlayer(string $message = 'invalid player'): self
    {
        return self::client(ApiCode::INVALID_PLAYER, $message);
    }

    public static function playerDisabled(string $message = 'player is disabled'): self
    {
        return self::client(ApiCode::PLAYER_DISABLED, $message);
    }

    public static function gameForbidden(string $message = 'game is forbidden to the player'): self
    {
        return self::client(ApiCode::GAME_FORBIDDEN, $message);
    }

    public static function currencyNotAllowed(string $message = 'currency is not allowed for the player'): self
    {
        return self::client(ApiCode::CURRENCY_NOT_ALLOWED, $message);
    }

    public static function badRequest(string $message = 'bad request'): self
    {
        return self::client(ApiCode::BAD_REQUEST, $message);
    }

    /** HTTP 400 with api_code 403 — the wallet contract never answers HTTP 403. */
    public static function invalidSignature(string $message = 'invalid signature'): self
    {
        return self::client(ApiCode::FORBIDDEN, $message);
    }

    public static function notFound(string $message = 'not found'): self
    {
        return self::client(ApiCode::NOT_FOUND, $message);
    }

    /** The transaction's id_provider was already rolled back (tombstoned). */
    public static function alreadyRolledBack(string $message = 'action already rolled back'): self
    {
        return self::client(ApiCode::ALREADY_ROLLED_BACK, $message);
    }

    // --- freespins ---------------------------------------------------------

    /** api_code 610: /freespins/finish names an issue_id you never issued. */
    public static function invalidFreespinsIssue(string $message = 'invalid freespins issue'): self
    {
        return self::client(ApiCode::FREESPINS_INVALID_ISSUE, $message);
    }

    /**
     * api_code 605: the issue cannot take this result in its current state —
     * on /freespins/finish, an issue you canceled.
     */
    public static function freespinsStateTransition(
        string $message = "can't change issue state from its current to requested",
    ): self {
        return self::client(ApiCode::FREESPINS_STATE_TRANSITION, $message);
    }

    // --- server ------------------------------------------------------------

    public static function internal(string $message = 'internal error'): self
    {
        return self::server(ApiCode::UNKNOWN_ERROR, $message);
    }

    public static function serviceUnavailable(string $message = 'service unavailable'): self
    {
        return self::server(ApiCode::SERVICE_UNAVAILABLE, $message);
    }

    /**
     * Escape hatch for an api_code without a dedicated constructor. It runs the
     * same balance check, so it cannot be used to bypass it.
     */
    public static function withApiCode(string $apiCode, string $message, ?Money $balance = null): self
    {
        $status = str_starts_with($apiCode, '5') ? 500 : 400;
        $twirp = $status >= 500 ? ApiCode::TWIRP_INTERNAL : ApiCode::TWIRP_INVALID_ARGUMENT;

        return new self($status, $twirp, $apiCode, $message, $balance);
    }

    /** @return array{code: string, msg: string, meta: array<string, string>} */
    public function toEnvelope(): array
    {
        $meta = ['api_code' => $this->apiCode, 'api_message' => $this->getMessage()];
        if ($this->balance !== null) {
            $meta['balance'] = (string) $this->balance;
        }

        return ['code' => $this->twirpCode, 'msg' => $this->getMessage(), 'meta' => $meta];
    }
}
