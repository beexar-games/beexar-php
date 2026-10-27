<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

final class FreespinsFinishResult
{
    /** @param Money $balance after the credit; on a repeat, the one you returned the first time */
    public function __construct(public readonly Money $balance)
    {
    }
}
