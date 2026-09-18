<?php

declare(strict_types=1);

namespace Beexar;

/**
 * The calls you send to Beexar: launch a session, list the catalogue.
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
        return $this->postSigned('/api/v1/softswiss/launcher/real', $request);
    }

    /**
     * Launches a demo session on a virtual balance. No wallet callbacks are
     * made during demo play.
     *
     * @param array<string, mixed> $request at least `game`
     */
    public function launchDemo(array $request): string
    {
        return $this->postSigned('/api/v1/softswiss/launcher/demo', $request);
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

    /** @param array<string, mixed> $payload */
    private function postSigned(string $path, array $payload): string
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

        return (string) ($decoded['launch_url'] ?? '');
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
