<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

final class BetWinTransactionResult
{
    public function __construct(
        /** Echo the platform's id back unchanged. */
        public readonly string $idProvider,
        /** YOUR transaction id. On a replay, return the one you stored the first time. */
        public readonly string $id,
        /**
         * Bonus balance moved by this transaction. The contract requires the
         * field; the SDK emits "0.00" when you leave it null.
         */
        public readonly ?Money $bonusAmount = null,
    ) {
    }
}
