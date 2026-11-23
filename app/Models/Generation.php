<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    /** Local only: created in our DB, not yet accepted by Pixelforge. */
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Status order. A generation only ever moves forward through this. */
    public const RANK = [
        self::PENDING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::SUCCEEDED => 3,
        self::FAILED => 3,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_min_nanos' => 'integer',
            'estimated_nanos' => 'integer',
            'estimated_max_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    /** Waiting on Pixelforge: candidates for the polling fallback. */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::PROCESSING])->whereNotNull('pixelforge_id');
    }

    public function toFrontend(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'estimated' => Money::format($this->estimated_nanos),
            'estimated_min' => Money::format($this->estimated_min_nanos),
            'estimated_max' => Money::format($this->estimated_max_nanos),
            'charged' => $this->charged_nanos !== null ? Money::format($this->charged_nanos) : null,
            'charged_exact' => $this->charged_nanos !== null ? Money::toDecimal($this->charged_nanos) : null,
            'usage' => $this->usage,
            'image_url' => $this->image_url,
            'image_expired' => $this->image_url_expires_at?->isPast() ?? false,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
