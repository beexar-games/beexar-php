<?php

declare(strict_types=1);

namespace Beexar;

use Beexar\Wallet\BalanceRequest;
use Beexar\Wallet\BalanceResult;
use Beexar\Wallet\BetWinRequest;
use Beexar\Wallet\BetWinResult;
use Beexar\Wallet\FinishRequest;
use Beexar\Wallet\FinishResult;
use Beexar\Wallet\RequestContext;
use Beexar\Wallet\RollbackRequest;
use Beexar\Wallet\RollbackResult;

/**
 * The four methods you implement against your own ledger. Everything else —
 * signature verification, parsing, validation, the Twirp error envelope — is
 * the SDK's job.
 *
 * Throw a WalletError to answer with a specific api_code. Anything else you
 * throw becomes an opaque 500 and its message never leaves your process.
 */
interface WalletHandler
{
    public function balance(BalanceRequest $request, RequestContext $context): BalanceResult;

    public function betWin(BetWinRequest $request, RequestContext $context): BetWinResult;

    public function rollback(RollbackRequest $request, RequestContext $context): RollbackResult;

    public function finish(FinishRequest $request, RequestContext $context): FinishResult;
}
