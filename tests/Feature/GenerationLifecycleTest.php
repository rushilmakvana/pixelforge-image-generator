<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\CreditLedger;
use App\Services\GenerationService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCatalog;
use App\Services\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.webhook_secret' => self::SECRET,
            'services.pixelforge.api_key' => 'test-key',
        ]);
        Cache::put('pixelforge.pricing', PricingCatalog::FALLBACK);

        $this->user = User::factory()->create();
        app(CreditLedger::class)->purchase($this->user, Money::parse('5'));
    }

    // ---------------------------------------------------------------- submit

    public function test_submitting_reserves_the_max_estimate_and_queues_the_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/generations', [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['', 'https://example.com/a.png'],
        ])->assertSessionHasNoErrors();

        $generation = Generation::sole();
        $this->assertSame(Generation::PENDING, $generation->status);
        $this->assertSame(['https://example.com/a.png'], $generation->reference_images);
        $this->assertSame(Money::parse('5') - $generation->estimated_max_nanos, $this->user->fresh()->balance_nanos);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_cannot_submit_without_enough_credits(): void
    {
        $poor = User::factory()->create();

        $this->actingAs($poor)->post('/generations', [
            'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'high',
        ])->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
    }

    public function test_job_sends_idempotency_key_and_metadata_then_marks_queued(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake(['*/generations' => Http::response($this->remote($generation, 'queued'), 202)]);

        (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationService::class));

        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $r['metadata'] === ['generation_id' => (string) $generation->id]);
        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
        $this->assertSame('gen_test', $generation->fresh()->pixelforge_id);
    }

    public function test_content_policy_rejection_fails_for_free(): void
    {
        $generation = $this->pendingGeneration();
        Http::fake(['*/generations' => Http::response(['error' => [
            'type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.',
        ]], 400)]);

        (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationService::class));

        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(Money::parse('5'), $this->user->fresh()->balance_nanos);
    }

    // -------------------------------------------------------------- webhooks

    public function test_succeeded_webhook_charges_actual_cost_plus_markup(): void
    {
        $generation = $this->acceptedGeneration();

        $this->webhook('evt_1', 'generation.succeeded', $this->remote($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        // 12×5 + 0 + 1100×40 + 1000×12.5 = 56,560 millionths → ×1.25
        $this->assertSame('0.056560000', Money::toDecimal($generation->provider_cost_nanos));
        $this->assertSame('0.070700000', Money::toDecimal($generation->charged_nanos));
        $this->assertSame(Money::parse('5') - $generation->charged_nanos, $this->user->fresh()->balance_nanos);
    }

    public function test_duplicate_webhook_is_recorded_once_and_charged_once(): void
    {
        $generation = $this->acceptedGeneration();
        $payload = $this->remote($generation, 'succeeded');

        $this->webhook('evt_1', 'generation.succeeded', $payload)->assertNoContent();
        $this->webhook('evt_1', 'generation.succeeded', $payload)->assertNoContent();
        // Even a *different* event id for the same outcome must not charge twice.
        $this->webhook('evt_2', 'generation.succeeded', $payload)->assertNoContent();

        $this->assertSame(1, CreditTransaction::where('generation_id', $generation->id)->where('type', 'charge')->count());
        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(Money::parse('5') - $generation->fresh()->charged_nanos, $this->user->fresh()->balance_nanos);
    }

    public function test_out_of_order_processing_after_succeeded_does_not_regress(): void
    {
        $generation = $this->acceptedGeneration();

        $this->webhook('evt_2', 'generation.succeeded', $this->remote($generation, 'succeeded'));
        $this->webhook('evt_1', 'generation.processing', $this->remote($generation, 'processing'));

        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
    }

    public function test_webhook_arriving_before_post_response_is_matched_via_metadata(): void
    {
        $generation = $this->pendingGeneration(); // no pixelforge_id yet

        $this->webhook('evt_1', 'generation.processing', $this->remote($generation, 'processing'));
        $this->assertSame(Generation::PROCESSING, $generation->fresh()->status);
        $this->assertSame('gen_test', $generation->fresh()->pixelforge_id);

        // The late POST response (queued) must not move it backwards.
        app(GenerationService::class)->applyRemote($generation, $this->remote($generation, 'queued'));
        $this->assertSame(Generation::PROCESSING, $generation->fresh()->status);
    }

    public function test_failed_generation_is_free(): void
    {
        $generation = $this->acceptedGeneration();

        $this->webhook('evt_1', 'generation.failed', $this->remote($generation, 'failed'));
        $this->webhook('evt_2', 'generation.succeeded', $this->remote($generation, 'succeeded')); // can't flip a final state

        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertNull($generation->charged_nanos);
        $this->assertSame(Money::parse('5'), $this->user->fresh()->balance_nanos);
    }

    public function test_invalid_or_stale_signatures_are_rejected(): void
    {
        $generation = $this->acceptedGeneration();
        $body = json_encode($this->event('evt_1', 'generation.succeeded', $this->remote($generation, 'succeeded')));

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $old = time() - 600;
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$old},v1=".WebhookSignature::sign(self::SECRET, $old, $body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
    }

    // --------------------------------------------------------------- helpers

    private function pendingGeneration(): Generation
    {
        Queue::fake();
        $this->actingAs($this->user)->post('/generations', [
            'prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'medium',
        ]);

        return Generation::latest('id')->first();
    }

    private function acceptedGeneration(): Generation
    {
        $generation = $this->pendingGeneration();
        app(GenerationService::class)->applyRemote($generation, $this->remote($generation, 'queued'));

        return $generation->fresh();
    }

    private function remote(Generation $generation, string $status): array
    {
        $done = in_array($status, ['succeeded', 'failed'], true);

        return [
            'id' => 'gen_test',
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => [],
            'metadata' => ['generation_id' => (string) $generation->id],
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/img.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? '2026-11-21T10:00:00Z' : null,
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100,
                'reasoning_tokens' => 1000, 'total_tokens' => 2112,
            ] : null,
            'rates' => PricingCatalog::FALLBACK['rates'],
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Could not render.'] : null,
            'created_at' => '2026-11-20T10:00:00Z',
            'started_at' => $status === 'queued' ? null : '2026-11-20T10:00:03Z',
            'completed_at' => $done ? '2026-11-20T10:00:20Z' : null,
        ];
    }

    private function event(string $id, string $type, array $object): array
    {
        return ['id' => $id, 'object' => 'event', 'type' => $type, 'created_at' => '2026-11-20T10:00:20Z', 'data' => ['object' => $object]];
    }

    private function webhook(string $id, string $type, array $object)
    {
        $body = json_encode($this->event($id, $type, $object));
        $t = time();

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1=".WebhookSignature::sign(self::SECRET, $t, $body),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
