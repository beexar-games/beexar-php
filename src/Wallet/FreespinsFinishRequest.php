<?php

declare(strict_types=1);

namespace Beexar\Wallet;

use Beexar\Money;

/**
 * The result of a freespins issue: what its freespins won in total, for you to
 * credit to the player you issued them to.
 */
final class FreespinsFinishRequest
{
    public function __construct(
        /** YOUR id for the issue — the issue_id you sent to Client::issueFreespins(). */
        public readonly string $issueId,
        /**
         * The total won, in the issue's currency at its scale. Zero or more —
         * zero ("0.00" in EUR, "0" in JPY) is a valid result, not an error: the
         * freespins were played and won nothing.
         */
        public readonly Money $amount,
    ) {
    }
}
