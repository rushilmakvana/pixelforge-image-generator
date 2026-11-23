<?php

namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a user's balance. Every change is a ledger row
 * plus an atomic increment of users.balance_nanos, in the caller's transaction.
 */
class CreditLedger
{
    public function purchase(User $user, int $amountNanos): CreditTransaction
    {
        return DB::transaction(fn () => $this->record($user->id, CreditTransaction::PURCHASE, $amountNanos, null,
            'Bought '.Money::format($amountNanos).' of credits'));
    }

    /**
     * Records the entry at most once per (generation, type). Returns null if it
     * already existed, so callers can't double-charge even if they try.
     * Must run inside a transaction that holds a lock on the generation row.
     */
    public function recordForGeneration(Generation $generation, string $type, int $amountNanos, string $description): ?CreditTransaction
    {
        $exists = CreditTransaction::where('generation_id', $generation->id)->where('type', $type)->exists();

        if ($exists) {
            return null;
        }

        return $this->record($generation->user_id, $type, $amountNanos, $generation->id, $description);
    }

    public function heldFor(Generation $generation): int
    {
        return (int) CreditTransaction::where('generation_id', $generation->id)
            ->where('type', CreditTransaction::HOLD)
            ->value('amount_nanos');
    }

    private function record(int $userId, string $type, int $amountNanos, ?int $generationId, string $description): CreditTransaction
    {
        $transaction = CreditTransaction::create([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nanos' => $amountNanos,
            'description' => $description,
        ]);

        User::whereKey($userId)->increment('balance_nanos', $amountNanos);

        return $transaction;
    }
}
