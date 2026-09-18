<?php

declare(strict_types=1);

namespace Beexar\Wallet;

/** Everything about the request that is not part of the parsed body. */
final class RequestContext
{
    /** @param array<string, string> $headers lower-cased header names */
    public function __construct(
        public readonly string $route,
        /** The exact bytes the signature was verified against. */
        public readonly string $rawBody,
        public readonly array $headers = [],
    ) {
    }
}
