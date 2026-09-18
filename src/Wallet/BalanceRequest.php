<?php

declare(strict_types=1);

namespace Beexar\Wallet;

final class BalanceRequest
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        public readonly string $gameId,
        public readonly ?string $sessionId = null,
    ) {
    }
}
