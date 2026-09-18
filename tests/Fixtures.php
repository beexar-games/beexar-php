<?php

declare(strict_types=1);

namespace Beexar\Tests;

/**
 * Locates the shared conformance fixtures.
 *
 * Two layouts, because this package is developed in the Beexar monorepo and
 * published as a standalone repository. In the published repository the
 * fixtures sit at the root as `conformance/`; in the monorepo they are the
 * single copy under `api/`, shared with the Node, Go and Python SDKs. The tests
 * that ship to operators therefore run unchanged in both places.
 */
final class Fixtures
{
    public static function root(): string
    {
        foreach ([
            __DIR__ . '/../conformance',                                 // published repository
            __DIR__ . '/../../../api/providers/softswiss/conformance',   // monorepo
        ] as $candidate) {
            if (is_file($candidate . '/manifest.json')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('conformance fixtures not found');
    }
}
