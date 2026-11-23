<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const HOLD = 'hold';

    public const RELEASE = 'release';

    public const CHARGE = 'charge';

    /** Manual correction that reverses a wrong charge; the original charge row stays for the audit trail. */
    public const REFUND = 'refund';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
