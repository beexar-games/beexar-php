<?php

declare(strict_types=1);

namespace Beexar;

/**
 * The calls you send to Beexar: launch a session, list the catalogue, issue
 * and cancel freespins, read the bet levels they are issued at.
 *
 * Every POST is signed with hex(HMAC-SHA256(body, apiSecret)) over the exact
 * bytes that go on the wire — the body is encoded once, signed, and sent.
 * Encoding twice is how signatures mysteriously stop matching.
 *
 * Transport is cURL by default. Pass your own sender to use Guzzle, a PSR-18
 * client, or anything else; it receives the final bytes and headers.
 */
final class Client
{
    public const PRODUCTION_BASE_URL = 'https://gateway.beexar.com';

    /** @var null|callable(string, string, array<string, string>, ?string): array{int, string} */
    private $sender;

    /**
     * @param string $casinoId  your operator slug, sent as casino_id
     * @param string $apiSecret from the backoffice; never ship it to a browser
     * @param null|callable(string, string, array<string, string>, ?string): array{int, string} $sender
     *        receives (method, url, headers, body) and returns [status, responseBody]
     */
    public function __construct(
        private readonly string $casinoId,
        private readonly string $apiSecret,
        private readonly string $baseUrl = self::PRODUCTION_BASE_URL,
        private readonly int $timeoutSeconds = 10,
        ?callable $sender = null,
    ) {
        if ($casinoId === '') {
            throw new \InvalidArgumentException('Client: casinoId is required');
        }
        if ($apiSecret === '') {
            throw new \InvalidArgumentException('Client: apiSecret is required');
        }
        $this->sender = $sender;
    }

    /**
     * Launches a real-money session and returns the URL to put in your iframe.
     *
     * @param array<string, mixed> $request at least `game` and `account`
     */
    public function launchReal(array $request): string
    {
        return (string) ($this->postSigned('/api/v1/softswiss/launcher/real', $request)['launch_url'] ?? '');
    }

    /**
     * Launches a demo session on a virtual balance. No wallet callbacks are
     * made during demo play.
     *
     * @param array<string, mixed> $request at least `game`
     */
    public function launchDemo(array $request): string
    {
        return (string) ($this->postSigned('/api/v1/softswiss/launcher/demo', $request)['launch_url'] ?? '');
    }

    /**
     * The games enabled for your operator. This endpoint is public and carries
     * no signature.
     *
     * @return list<array<string, mixed>>
     */
    public function listGames(?string $operator = null, ?bool $active = null): array
    {
        $query = ['operator' => $operator ?? $this->casinoId];
        if ($active !== null) {
            $query['active'] = $active ? 'true' : 'false';
        }
        $url = $this->baseUrl . '/api/v1/operator/games?' . http_build_query($query);

        [$status, $body] = $this->send('GET', $url, ['Accept' => 'application/json'], null);
        $decoded = self::decode($body);
        if ($status !== 200) {
            throw ApiError::fromEnvelope($status, $decoded);
        }

        /** @var list<array<string, mixed>> $games */
        $games = $decoded['games'] ?? [];

        return $games;
    }

    /**
     * Freespins/Issue: gives a player free rounds in the listed games. The
     * player finds them on their next launchReal() of one of those games; what
     * they win arrives once, on your /freespins/finish callback.
     *
     * Record the issue on your side before calling this — the result names only
     * issue_id. A repeat with the same terms answers like the first call, so
     * retry a timeout or a 5xx with the SAME request: a valid_until computed
     * again is other terms, and other terms under one issue_id answer 610.
     *
     * @param array{
     *     issue_id: string,
     *     games: list<string>,
     *     freespins_quantity: int,
     *     bet_level: int,
     *     valid_until: string,
     *     account: array<string, mixed>
     * } $request `bet_level` comes from betLevels(); `valid_until` is UTC with a
     *            Z, at most one month ahead: gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400);
     *            `account` is the launch account — `id` and `currency` decide
     *            whose freespins these are
     */
    public function issueFreespins(array $request): void
    {
        // The answer is an empty object; a 200 is all there is to read.
        $this->postSigned('/api/v1/softswiss/freespins/issue', $request);
    }

    /**
     * Freespins/Cancel, at any time — even mid-play. A canceled issue never
     * reaches /freespins/finish: what it won is forfeited. Canceling an issue
     * that has already been played out is refused with api_code 620.
     *
     * @param array{issue_id: string} $request
     */
    public function cancelFreespins(array $request): void
    {
        $this->postSigned('/api/v1/softswiss/freespins/cancel', $request);
    }

    /**
     * Bet/Levels: the bet levels freespins can be issued at, per game and
     * currency. `bet_level` in issueFreespins() is one of these. Unknown games
     * and currencies are ignored; leave a filter out for all of them.
     *
     * @param null|list<string> $games      game slugs
     * @param null|list<string> $currencies currency codes
     *
     * @return list<array{games: list<string>, currencies: list<array{currency: string, bet_levels: list<array{bet_level: int, amount: string}>}>}>
     *         `amount` is the TOTAL bet of one round, a decimal string — Money::parse() it for arithmetic
     */
    public function betLevels(?array $games = null, ?array $currencies = null): array
    {
        $request = [];
        if ($games !== null) {
            $request['games'] = array_values($games);
        }
        if ($currencies !== null) {
            $request['currencies'] = array_values($currencies);
        }

        /** @var list<array{games: list<string>, currencies: list<array{currency: string, bet_levels: list<array{bet_level: int, amount: string}>}>}> $levels */
        $levels = $this->postSigned('/api/v1/softswiss/bet/levels', $request)['bet_levels'] ?? [];

        return $levels;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the decoded 200 answer
     */
    private function postSigned(string $path, array $payload): array
    {
        $payload['casino_id'] = $this->casinoId;

        // Encode ONCE. The signature and the request body are the same string,
        // so they cannot disagree.
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        [$status, $response] = $this->send(
            'POST',
            $this->baseUrl . $path,
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                Signature::HEADER => Signature::sign($body, $this->apiSecret),
            ],
            $body,
        );

        $decoded = self::decode($response);
        if ($status !== 200) {
            throw ApiError::fromEnvelope($status, $decoded);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->sender !== null) {
            return ($this->sender)($method, $url, $headers, $body);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('beexar: could not initialise cURL');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new \RuntimeException('beexar: request failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, (string) $response];
    }

    /** @return array<string, mixed> */
    private static function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : ['msg' => $body];
    }
}
