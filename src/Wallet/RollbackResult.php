<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

final class RollbackResult
{
    /** @param list<RollbackTransactionResult> $transactions */
    public function __construct(
        public readonly Money $balance,
        public readonly string $roundId,
        public readonly array $transactions,
    ) {
    }
}
