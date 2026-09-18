<?php

declare(strict_types=1);

namespace Beexar\Wallet;

/** Closes a round. It carries no game_id. */
final class FinishRequest
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        public readonly string $roundId,
        public readonly ?string $sessionId = null,
    ) {
    }
}
