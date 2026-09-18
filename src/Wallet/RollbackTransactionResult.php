<?php

declare(strict_types=1);

namespace Beexar\Wallet;

final class RollbackTransactionResult
{
    public function __construct(
        public readonly string $idProvider,
        /** Your transaction id, or "" when there was nothing to reverse. */
        public readonly string $id,
    ) {
    }
}
