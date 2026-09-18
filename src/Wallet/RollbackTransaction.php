<?php

declare(strict_types=1);

namespace Beexar\Wallet;

final class RollbackTransaction
{
    public function __construct(
        public readonly string $idProvider,
        /** The id_provider of the transaction being reversed. */
        public readonly string $originalIdProvider,
    ) {
    }
}
