<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

final class BetWinResult
{
    /** @param list<BetWinTransactionResult> $transactions one per request transaction, same order */
    public function __construct(
        /** YOUR round id. */
        public readonly string $roundId,
        /** Balance after all transactions in this request. */
        public readonly Money $balance,
        public readonly array $transactions,
    ) {
    }
}
