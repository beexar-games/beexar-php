<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

final class BalanceResult
{
    public function __construct(public readonly Money $balance)
    {
    }
}
