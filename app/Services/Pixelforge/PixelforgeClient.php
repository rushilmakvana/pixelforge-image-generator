<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper. Retrying is the caller's job (see SubmitGeneration),
 * because the right reaction differs per error code.
 */
class PixelforgeClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: string[], metadata?: array<string,string>}  $params
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->request()->get('/generations/'.rawurlencode($id)));
    }

    public function pricing(): array
    {
        return $this->send(fn () => Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('/pricing'));
    }

    /** Per UTC day of completion, for the API key in use. Max 92 days per call. */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw PixelforgeException::network($e->getMessage());
        }

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
