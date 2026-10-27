<?php

declare(strict_types=1);

namespace Beexar;

/**
 * An error answer from the Beexar gateway to one of YOUR calls
 * (launchReal, launchDemo, listGames, issueFreespins, cancelFreespins,
 * betLevels).
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $twirpCode,
        public readonly string $apiCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $envelope */
    public static function fromEnvelope(int $status, array $envelope): self
    {
        $meta = \is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];
        $message = (string) ($meta['api_message'] ?? $envelope['msg'] ?? 'unknown error');

        return new self(
            $status,
            (string) ($envelope['code'] ?? ApiCode::TWIRP_INTERNAL),
            (string) ($meta['api_code'] ?? $status),
            $message,
        );
    }

    /** 5xx only. A 400 on this contract is terminal — retrying changes nothing. */
    public function isRetryable(): bool
    {
        return $this->httpStatus >= 500;
    }

    /**
     * True when the gateway rejected the request signature. Keyed on api_code,
     * because the status alone is ambiguous.
     */
    public function isSignatureError(): bool
    {
        return $this->apiCode === ApiCode::FORBIDDEN;
    }
}
