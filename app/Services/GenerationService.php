<?php

namespace App\Services;

use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\InsufficientCredits;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingCatalog;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Owns a generation's lifecycle and its money.
 *
 * Every state change goes through applyRemote() or failSubmission(), which
 * lock the generation row, only ever move status forward, and settle credits
 * exactly once when the generation reaches a final state. That is what makes
 * duplicate, out-of-order and early webhooks (and polling racing them) safe.
 */
class GenerationService
{
    public function __construct(
        private PricingCalculator $calculator,
        private PricingCatalog $catalog,
        private CreditLedger $ledger,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: string[]}  $input
     *
     * @throws InsufficientCredits
     */
    public function create(User $user, array $input): Generation
    {
        $estimate = $this->calculator->estimate(
            $this->catalog->current(),
            mb_strlen($input['prompt']),
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
        );

        $generation = DB::transaction(function () use ($user, $input, $estimate) {
            // Lock the user so two concurrent submits can't both spend the same credits.
            $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('balance_nanos');

            if ($balance < $estimate->chargeMax) {
                throw new InsufficientCredits($estimate->chargeMax, $balance);
            }

            $generation = $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => array_values($input['reference_images']),
                'status' => Generation::PENDING,
                'estimated_min_nanos' => $estimate->chargeMin,
                'estimated_nanos' => $estimate->chargeExpected,
                'estimated_max_nanos' => $estimate->chargeMax,
            ]);

            // Reserve the worst case; the difference comes back when it finishes.
            $this->ledger->recordForGeneration($generation, CreditTransaction::HOLD, -$estimate->chargeMax,
                'Reserved for generation #'.$generation->id);

            return $generation;
        });

        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }

    /**
     * Apply a generation object from Pixelforge (POST response, GET poll or webhook).
     */
    public function applyRemote(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            /** @var Generation $generation */
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $remoteStatus = $remote['status'] ?? null;

            if (! isset(Generation::RANK[$remoteStatus]) || $remoteStatus === Generation::PENDING) {
                throw new UnexpectedValueException("Unknown Pixelforge status [{$remoteStatus}]");
            }

            if ($generation->pixelforge_id !== null && $generation->pixelforge_id !== $remote['id']) {
                Log::error('Pixelforge id mismatch', ['generation' => $generation->id, 'remote' => $remote['id']]);

                return $generation;
            }

            // A webhook can arrive before the POST response, so either may be first to tell us the id.
            $generation->pixelforge_id ??= $remote['id'];
            $generation->submitted_at ??= now();

            // We gave up submitting (e.g. timeouts) but Pixelforge did create it: let reality win.
            $lostSubmission = $generation->status === Generation::FAILED && $generation->getOriginal('pixelforge_id') === null;
            $currentRank = $lostSubmission ? 0 : Generation::RANK[$generation->status];

            $isStale = $generation->isFinal() && ! $lostSubmission
                || Generation::RANK[$remoteStatus] < $currentRank;

            if ($isStale) {
                $generation->save();

                return $generation;
            }

            $generation->status = $remoteStatus;
            $generation->status_note = null;
            $generation->started_at = self::time($remote['started_at'] ?? null) ?? $generation->started_at;

            if ($remoteStatus === Generation::SUCCEEDED) {
                $this->recordSuccess($generation, $remote);
            } elseif ($remoteStatus === Generation::FAILED) {
                $generation->error_code = $remote['error']['code'] ?? 'generation_failed';
                $generation->error_message = $remote['error']['message'] ?? 'The generation failed.';
                $generation->completed_at = self::time($remote['completed_at'] ?? null) ?? now();
            }

            $generation->save();

            if ($generation->isFinal()) {
                $this->settle($generation);
            }

            return $generation;
        });
    }

    /**
     * Pixelforge never accepted this generation (rejected, or we gave up retrying). Free.
     */
    public function failSubmission(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status !== Generation::PENDING) {
                return; // Already accepted (e.g. a webhook beat us); nothing to fail.
            }

            $generation->update([
                'status' => Generation::FAILED,
                'status_note' => null,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
            ]);

            $this->settle($generation);
        });
    }

    private function recordSuccess(Generation $generation, array $remote): void
    {
        $usage = $remote['usage'] ?? null;
        $rates = $remote['rates'] ?? null;

        if (! is_array($usage) || ! is_array($rates)) {
            throw new UnexpectedValueException("Succeeded generation {$remote['id']} is missing usage or rates");
        }

        // Bill with the rates frozen on the generation at creation, not today's rates.
        $providerCost = $this->calculator->providerCost($usage, $rates);

        $generation->fill([
            'usage' => $usage,
            'rates' => $rates,
            'provider_cost_nanos' => $providerCost,
            'charged_nanos' => $this->calculator->userCharge($providerCost),
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => self::time($remote['image_url_expires_at'] ?? null),
            'completed_at' => self::time($remote['completed_at'] ?? null) ?? now(),
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /** Release the hold, and charge the actual cost if it succeeded. Idempotent. */
    private function settle(Generation $generation): void
    {
        $held = $this->ledger->heldFor($generation);

        if ($held !== 0) {
            $this->ledger->recordForGeneration($generation, CreditTransaction::RELEASE, -$held,
                'Released reservation for generation #'.$generation->id);
        }

        if ($generation->status === Generation::SUCCEEDED) {
            $this->ledger->recordForGeneration($generation, CreditTransaction::CHARGE, -$generation->charged_nanos,
                'Generation #'.$generation->id.' (Pixelforge '.Money::format($generation->provider_cost_nanos).' + markup)');
        }
    }

    private static function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
