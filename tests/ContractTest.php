<?php

declare(strict_types=1);

namespace Beexar\Tests;

use Beexar\ApiCode;
use Beexar\WalletServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP SDK has no code generator: openapi-generator is a JAR, and this
 * pipeline has no Docker-in-Docker to run one in. What replaces it is this test.
 *
 * contract.json is produced from the four OpenAPI documents by
 * sdk/tools/gen-contract.mjs. Here we assert that the SDK's own view of the
 * contract still matches it — the routes, the required fields the parser
 * enforces, and the api_codes that must carry a balance. A field added to
 * wallet.yaml fails this test rather than reaching an operator as a silently
 * dropped value.
 */
final class ContractTest extends TestCase
{
    

    /** @return array<string, mixed> */
    private static function contract(): array
    {
        $raw = file_get_contents(Fixtures::root() . "/contract.json");
        self::assertIsString($raw, 'contract.json is missing — run `make sdk-generate`');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testEverySpecIsVersioned(): void
    {
        $contracts = self::contract()['contracts'];
        foreach ([
            'api/providers/softswiss/wallet.yaml',
            'api/softswiss/gateway.yaml',
            'api/gateway.yaml',
            'api/common/schemas.yaml',
        ] as $path) {
            self::assertArrayHasKey($path, $contracts);
            self::assertMatchesRegularExpression('/^v\.\d{4}\.\d{2}\.\d{2}$/', $contracts[$path]);
        }
    }

    public function testRequiredFieldsMatchTheSpec(): void
    {
        // Keyed by spec: two specs declare an ErrorResponseMeta and they differ.
        $schemas = self::contract()['schemas']['wallet'];

        // What the SDK's parser insists on, per route. If the spec gains a
        // required field this list stops matching and the test says which one.
        $expected = [
            'PlayerBalanceRequest' => ['account_id', 'currency', 'game_id'],
            'RoundBetWinRequest' => ['account_id', 'currency', 'game_id', 'round_id', 'transactions'],
            'RoundBetWinRequestTransaction' => ['amount', 'id_provider', 'type'],
            'RoundRollbackRequestTransaction' => ['id_provider', 'original_id_provider', 'type'],
            'RoundFinishRequest' => ['account_id', 'currency', 'round_id'],
            'FreespinsFinishRequest' => ['amount', 'issue_id'],
        ];

        foreach ($expected as $schema => $required) {
            self::assertArrayHasKey($schema, $schemas, "schema {$schema} vanished from the spec");
            self::assertSame(
                $required,
                $schemas[$schema]['required'],
                "the parser and the spec disagree about what {$schema} requires",
            );
        }

        // Rollback is the one place the SDK is deliberately more permissive
        // than the spec: game_id is required there and always sent, but we
        // accept its absence rather than refuse a request we could serve.
        self::assertContains('game_id', $schemas['RoundRollbackRequest']['required']);
    }

    /**
     * Every property the spec declares must reach the handler.
     *
     * The required-list check above catches a newly mandatory field; this one
     * catches a newly OPTIONAL field, which is the quieter failure — the parser
     * keeps working and the value is simply dropped on the floor.
     *
     * @return array<string, array{string, class-string}>
     */
    public static function walletSchemas(): array
    {
        return [
            'PlayerBalanceRequest' => ['PlayerBalanceRequest', \Beexar\Wallet\BalanceRequest::class],
            'RoundBetWinRequest' => ['RoundBetWinRequest', \Beexar\Wallet\BetWinRequest::class],
            'RoundBetWinRequestTransaction' => ['RoundBetWinRequestTransaction', \Beexar\Wallet\BetWinTransaction::class],
            'RoundRollbackRequest' => ['RoundRollbackRequest', \Beexar\Wallet\RollbackRequest::class],
            'RoundFinishRequest' => ['RoundFinishRequest', \Beexar\Wallet\FinishRequest::class],
            'FreespinsFinishRequest' => ['FreespinsFinishRequest', \Beexar\Wallet\FreespinsFinishRequest::class],
        ];
    }

    /**
     * @param class-string $dto
     */
    #[DataProvider('walletSchemas')]
    public function testEveryPropertyInTheSpecReachesTheHandler(string $schema, string $dto): void
    {
        $wallet = self::contract()['schemas']['wallet'];
        self::assertArrayHasKey($schema, $wallet, "{$schema} vanished from the spec");

        // Constructor parameters are camelCase while the wire stays snake_case;
        // compare on the normalised form so the mapping itself is what is checked.
        $parameters = [];
        foreach ((new \ReflectionClass($dto))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter->getName()))] = true;
        }

        foreach (array_keys($wallet[$schema]['properties']) as $property) {
            // `type` on a rollback transaction is fixed to the constant
            // "rollback": the SDK validates it and does not hand back a field
            // that can only hold one value.
            if ($schema === 'RoundRollbackRequestTransaction' && $property === 'type') {
                continue;
            }
            self::assertArrayHasKey(
                $property,
                $parameters,
                "{$schema}.{$property} is in the spec but not on {$dto}",
            );
        }
    }

    public function testCurrencyPatternMatchesTheSpec(): void
    {
        // Keyed by spec: two specs declare an ErrorResponseMeta and they differ.
        $schemas = self::contract()['schemas']['wallet'];
        $fromSpec = $schemas['PlayerBalanceRequest']['properties']['currency']['pattern'];
        self::assertSame($fromSpec, \Beexar\Currency::PATTERN_SOURCE);
    }

    public function testRoutesAreTheFiveTheContractDefines(): void
    {
        self::assertSame(['/balance', '/betwin', '/rollback', '/finish', '/freespins/finish'], WalletServer::ROUTES);

        // The fifth answers the spec's FreespinsFinishResponse, which the
        // server builds as a lone balance.
        $raw = file_get_contents(Fixtures::root() . '/contract.json');
        self::assertIsString($raw);
        $wallet = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['schemas']['wallet'];
        self::assertArrayHasKey('FreespinsFinishResponse', $wallet);
        self::assertSame(['balance'], $wallet['FreespinsFinishResponse']['required']);
    }

    public function testFundsRelatedCodesAreExactlyTheThree(): void
    {
        // The platform reads meta.balance with the error swallowed, so nothing
        // downstream complains if we get this wrong — which is why it is pinned.
        foreach ([ApiCode::INSUFFICIENT_FUNDS, ApiCode::BET_LIMIT_REACHED, ApiCode::MAX_BET_EXCEEDED] as $code) {
            self::assertTrue(ApiCode::isFundsRelated($code), "api_code {$code} must carry a balance");
        }
        foreach ([
            ApiCode::INVALID_PLAYER, ApiCode::FORBIDDEN, ApiCode::ALREADY_ROLLED_BACK, ApiCode::BAD_REQUEST,
            ApiCode::FREESPINS_INVALID_ISSUE, ApiCode::FREESPINS_STATE_TRANSITION,
        ] as $code) {
            self::assertFalse(ApiCode::isFundsRelated($code), "api_code {$code} must not require a balance");
        }
    }
}
