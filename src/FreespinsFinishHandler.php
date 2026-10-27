<?php

declare(strict_types=1);

namespace Beexar;

use Beexar\Wallet\FreespinsFinishRequest;
use Beexar\Wallet\FreespinsFinishResult;
use Beexar\Wallet\RequestContext;

/**
 * The optional fifth method, for a wallet that issues freespins:
 * /freespins/finish. Implement it on the same class as WalletHandler; a
 * handler without it answers that route the way an unknown one is answered
 * (api_code 404).
 *
 * Credit the amount to the player of the issue once per issue_id. The platform
 * sends the result once and retries the same body until it gets a 200, so a
 * repeat must answer the balance you stored the first time and credit nothing.
 *
 * Throw a WalletError to answer with a specific api_code — 610 for an issue
 * you never issued, 605 for one you canceled. Anything else you throw becomes
 * an opaque 500, and the platform retries.
 */
interface FreespinsFinishHandler
{
    public function freespinsFinish(FreespinsFinishRequest $request, RequestContext $context): FreespinsFinishResult;
}
