<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    private const GENERATION_EVENTS = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    public function __invoke(Request $request, GenerationService $generations): Response
    {
        $signature = new WebhookSignature(
            (string) config('services.pixelforge.webhook_secret'),
            (int) config('services.pixelforge.webhook_tolerance'),
        );

        // Verify against the raw bytes, before any JSON decoding.
        if (! $signature->isValid($request->getContent(), $request->header('Pixelforge-Signature'))) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response('Malformed event', 400);
        }

        if (! in_array($event['type'], self::GENERATION_EVENTS, true)) {
            return response()->noContent(); // ping and anything new: acknowledge, nothing to do.
        }

        $remote = $event['data']['object'] ?? [];

        try {
            // Recording the event and applying it commit together: if applying fails,
            // the event isn't marked as seen and Pixelforge's retry gets another go.
            DB::transaction(function () use ($event, $remote, $generations) {
                $generation = $this->findGeneration($remote);

                WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'pixelforge_generation_id' => $remote['id'] ?? null,
                    'generation_id' => $generation?->id,
                    'payload' => $event,
                ]);

                if ($generation) {
                    $generations->applyRemote($generation, $remote);
                } else {
                    Log::warning('Pixelforge webhook for unknown generation', ['event' => $event['id'], 'generation' => $remote['id'] ?? null]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Duplicate delivery of an event we already processed.
        }

        return response()->noContent();
    }

    private function findGeneration(array $remote): ?Generation
    {
        // Our generation's UUID travels in metadata, so this works even before we've stored pixelforge_id.
        // It is a UUID rather than our row id because row ids repeat across environments (local, production)
        // that share a Pixelforge key; matching on row id let one environment's webhook update another's row.
        $key = $remote['metadata']['generation_key'] ?? null;

        if (is_string($key) && $key !== '') {
            $generation = Generation::where('idempotency_key', $key)->first();

            if ($generation && in_array($generation->pixelforge_id, [null, $remote['id'] ?? null], true)) {
                return $generation;
            }
        }

        return isset($remote['id']) ? Generation::where('pixelforge_id', $remote['id'])->first() : null;
    }
}
