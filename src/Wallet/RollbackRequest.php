<?php

declare(strict_types=1);

namespace Beexar\Wallet;

final class RollbackRequest
{
    /** @param list<RollbackTransaction> $transactions */
    public function __construct(
        public readonly string $accountId,
        public readonly string $currency,
        /**
         * Required by the contract and always sent by the platform, but the SDK
         * accepts its absence and gives you "" rather than rejecting a request
         * it could have served. Liberal in, strict out.
         */
        public readonly string $gameId,
        /** Note the name: rollback uses round_id_provider, betwin and finish use round_id. */
        public readonly string $roundIdProvider,
        public readonly bool $finished,
        public readonly array $transactions,
        public readonly ?string $sessionId = null,
    ) {
    }
}
