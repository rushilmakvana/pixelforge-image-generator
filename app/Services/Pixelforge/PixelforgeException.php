<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $error['message'] ?? "Pixelforge returned HTTP {$response->status()}",
            status: $response->status(),
            errorCode: $error['code'] ?? null,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            details: $error,
        );
    }

    /** No HTTP response at all (timeout, DNS, connection reset). Outcome unknown. */
    public static function network(string $message): self
    {
        return new self("Network error talking to Pixelforge: {$message}", errorCode: 'network_error');
    }

    /** Temporary condition: retrying the same request later can succeed. */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'network_error'], true)
            || ($this->status !== null && $this->status >= 500);
    }
}
