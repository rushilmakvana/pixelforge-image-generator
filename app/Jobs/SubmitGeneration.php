<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Every attempt uses the generation's own Idempotency-Key, so retries after
 * timeouts or 5xx can never create (or bill) a second generation.
 * Temporary rejections (rate limit, concurrency limit, overload) are retried
 * after Retry-After with backoff; permanent ones fail the generation for free.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Releases count as attempts. ~60 attempts with a 30 s cap ≈ up to half an hour of waiting. */
    public int $tries = 60;

    public int $maxExceptions = 3;

    public function __construct(public int $generationId) {}

    public function handle(PixelforgeClient $client, GenerationService $service): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->status !== Generation::PENDING) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                // Lets webhooks find our row even if they arrive before this response does.
                // The UUID, not the row id: row ids repeat across environments that share a key.
                'metadata' => ['generation_key' => $generation->idempotency_key],
            ], $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $this->handleRejection($generation, $service, $e);

            return;
        }

        $service->applyRemote($generation, $remote);
    }

    private function handleRejection(Generation $generation, GenerationService $service, PixelforgeException $e): void
    {
        if ($e->isRetryable() && $this->attempts() < $this->tries) {
            $delay = $this->retryDelay($e);
            $generation->update(['status_note' => $this->waitingNote($e, $delay)]);
            $this->release($delay);

            return;
        }

        $service->failSubmission($generation, $e->errorCode ?? 'submission_failed', $this->userMessage($e));
    }

    /** Honour Retry-After, back off exponentially when it's absent, add jitter so retries don't stampede. */
    private function retryDelay(PixelforgeException $e): int
    {
        $backoff = min(30, 2 ** min($this->attempts(), 5));

        return max($e->retryAfter ?? 0, $backoff) + random_int(0, 2);
    }

    private function waitingNote(PixelforgeException $e, int $delay): string
    {
        return match ($e->errorCode) {
            'concurrency_limit_exceeded' => "Waiting for a free slot (3 generations run at once). Retrying in {$delay}s.",
            'rate_limit_exceeded' => "Too many requests right now. Retrying in {$delay}s.",
            'overloaded' => "Pixelforge is overloaded. Retrying in {$delay}s.",
            default => "Couldn't reach Pixelforge. Retrying in {$delay}s.",
        };
    }

    private function userMessage(PixelforgeException $e): string
    {
        $free = ' You were not charged.';

        return match ($e->errorCode) {
            'content_policy_violation' => 'This prompt was rejected by the content policy.'.$free,
            'validation_failed' => 'Pixelforge rejected the request: '.$e->getMessage().$free,
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Try again after midnight UTC.'.$free,
            'invalid_api_key' => 'The image service is misconfigured. Please contact support.'.$free,
            default => $e->isRetryable()
                ? 'Pixelforge stayed busy for too long, so we stopped retrying.'.$free
                : 'Pixelforge rejected the request: '.$e->getMessage().$free,
        };
    }

    public function failed(?Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation) {
            app(GenerationService::class)->failSubmission($generation, 'submission_failed',
                'Something went wrong while submitting. You were not charged.');
        }
    }
}
