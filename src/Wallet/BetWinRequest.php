<?php

declare(strict_types=1);

namespace Beexar\Wallet;

final class BetWinRequest
{
    /** @param list<BetWinTransaction> $transactions in request order; apply them in this order, atomically */
    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        public readonly string $gameId,
        public readonly string $roundId,
        public readonly bool $finished,
        public readonly array $transactions,
        public readonly ?string $sessionId = null,
    ) {
    }
}
