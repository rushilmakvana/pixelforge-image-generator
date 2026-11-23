<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polling fallback: webhook delivery isn't guaranteed, so in-flight
 * generations that have gone quiet are fetched and applied the same way.
 */
class SyncGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public int $generationId) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    public function handle(PixelforgeClient $client, GenerationService $service): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->isFinal() || ! $generation->pixelforge_id) {
            return;
        }

        $service->applyRemote($generation, $client->getGeneration($generation->pixelforge_id));
    }
}
