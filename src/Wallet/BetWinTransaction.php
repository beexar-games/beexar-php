<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

final class BetWinTransaction
{
    public const TYPE_BET = 'bet';
    public const TYPE_WIN = 'win';

    public function __construct(
        public readonly string $idProvider,
        /** Either self::TYPE_BET or self::TYPE_WIN. */
        public readonly string $type,
        /** Always greater than zero — the SDK rejects 0 before your code runs. */
        public readonly Money $amount,
    ) {
    }
}
