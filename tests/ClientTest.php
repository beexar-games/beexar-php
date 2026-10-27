<?php

declare(strict_types=1);

namespace Beexar\Tests;

use Beexar\ApiCode;
use Beexar\ApiError;
use Beexar\Client;
use Beexar\Signature;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const SECRET = 'launcher-test-secret';

    private const ISSUE = [
        'issue_id' => 'fs-1',
        'games' => ['dice', 'plinko'],
        'freespins_quantity' => 10,
        'bet_level' => 2,
        'valid_until' => '2026-11-30T12:00:00Z',
        'account' => ['id' => 'player_1', 'currency' => 'EUR'],
    ];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /** A client whose transport answers with one status and body, and records what went on the wire. */
    private function client(int $status, string $response): Client
    {
        $this->calls = [];

        return new Client(
            'casinoxyz',
            self::SECRET,
            'https://gw.test',
            10,
            function (string $method, string $url, array $headers, ?string $body) use ($status, $response): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                return [$status, $response];
            },
        );
    }

    public function testIssueFreespinsSignsTheExactBytesItSends(): void
    {
        $this->client(200, '{}')->issueFreespins(self::ISSUE);

        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://gw.test/api/v1/softswiss/freespins/issue', $call['url']);
        // The signature and the body come from one encoding, so they cannot
        // drift — this is the property that keeps the call accepted.
        self::assertIsString($call['body']);
        self::assertTrue(Signature::verify($call['body'], $call['headers'][Signature::HEADER], self::SECRET));
        // Integers stay integers on the wire; the platform's schema refuses "10".
        self::assertSame(self::ISSUE + ['casino_id' => 'casinoxyz'], json_decode($call['body'], true));
    }

    public function testCancelFreespinsPostsTheIssueId(): void
    {
        $this->client(200, '{}')->cancelFreespins(['issue_id' => 'fs-1']);

        $call = $this->calls[0];
        self::assertSame('https://gw.test/api/v1/softswiss/freespins/cancel', $call['url']);
        self::assertSame(['issue_id' => 'fs-1', 'casino_id' => 'casinoxyz'], json_decode((string) $call['body'], true));
        self::assertTrue(Signature::verify((string) $call['body'], $call['headers'][Signature::HEADER], self::SECRET));
    }

    public function testBetLevelsSendsTheFiltersAndUnwrapsTheAnswer(): void
    {
        $levels = [[
            'games' => ['dice'],
            'currencies' => [[
                'currency' => 'EUR',
                'bet_levels' => [['bet_level' => 1, 'amount' => '0.10'], ['bet_level' => 2, 'amount' => '0.20']],
            ]],
        ]];
        $client = $this->client(200, (string) json_encode(['bet_levels' => $levels]));

        self::assertSame($levels, $client->betLevels(games: ['dice'], currencies: ['EUR']));
        self::assertSame('https://gw.test/api/v1/softswiss/bet/levels', $this->calls[0]['url']);
        self::assertSame(
            ['games' => ['dice'], 'currencies' => ['EUR'], 'casino_id' => 'casinoxyz'],
            json_decode((string) $this->calls[0]['body'], true),
        );
    }

    public function testBetLevelsWithoutFiltersAsksForEverything(): void
    {
        self::assertSame([], $this->client(200, '{"bet_levels":[]}')->betLevels());
        // An absent filter means "all"; an empty array would say something else.
        self::assertSame('{"casino_id":"casinoxyz"}', $this->calls[0]['body']);
    }

    public function testARefusalIsAnApiErrorKeyedOnApiCode(): void
    {
        $client = $this->client(
            400,
            '{"code":"invalid_argument","msg":"issue_id fs-1 was issued with other terms: valid_until",'
            . '"meta":{"api_code":"610","api_message":"issue_id fs-1 was issued with other terms: valid_until"}}',
        );

        try {
            $client->issueFreespins(self::ISSUE);
            self::fail('a refusal must throw');
        } catch (ApiError $error) {
            self::assertSame(ApiCode::FREESPINS_INVALID_ISSUE, $error->apiCode);
            self::assertFalse($error->isRetryable());
            self::assertStringContainsString('valid_until', $error->getMessage());
        }
    }

    public function testAServerErrorIsRetryable(): void
    {
        $client = $this->client(500, '{"code":"internal","meta":{"api_code":"500"}}');

        try {
            $client->cancelFreespins(['issue_id' => 'fs-1']);
            self::fail('a 500 must throw');
        } catch (ApiError $error) {
            self::assertTrue($error->isRetryable());
        }
    }

    public function testLaunchStillReturnsTheUrl(): void
    {
        $url = $this->client(200, '{"launch_url":"https://games/x"}')->launchReal([
            'game' => 'dice',
            'account' => ['id' => 'player_1', 'currency' => 'EUR'],
        ]);
        self::assertSame('https://games/x', $url);
    }
}
