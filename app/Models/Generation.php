<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property string $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string>|null $reference_images
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $stored_image_path
 * @property int|null $provider_cost_nano
 * @property int|null $charged_nano
 * @property int $estimated_provider_cost_nano
 * @property int $estimated_charged_nano
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $submission_attempted_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $last_synced_at
 */
#[Fillable([
    'user_id',
    'pixelforge_id',
    'idempotency_key',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'rates',
    'usage',
    'image_url',
    'image_url_expires_at',
    'stored_image_path',
    'provider_cost_nano',
    'charged_nano',
    'estimated_provider_cost_nano',
    'estimated_charged_nano',
    'error_code',
    'error_message',
    'started_at',
    'completed_at',
    'submitted_at',
    'last_synced_at',
])]
class Generation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * Higher never regresses to lower. Both terminal states share the top rank so a
     * late `processing` or `queued` event can never reopen a finished generation.
     *
     * @var array<string, int>
     */
    public const STATUS_RANK = [
        self::STATUS_PENDING => -1,
        self::STATUS_QUEUED => 0,
        self::STATUS_PROCESSING => 1,
        self::STATUS_SUCCEEDED => 2,
        self::STATUS_FAILED => 2,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'submission_attempted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Statuses that can still change. The poller and the UI both read this instead of
     * repeating the list, so a new in-flight status only has to be added here.
     *
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_PROCESSING];
    }

    /**
     * @return array<int, string>
     */
    public static function terminalStatuses(): array
    {
        return [self::STATUS_SUCCEEDED, self::STATUS_FAILED];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::terminalStatuses(), true);
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isSettled(): bool
    {
        return $this->provider_cost_nano !== null;
    }

    /**
     * The image the browser should show: our stored copy first, then Pixelforge's
     * expiring URL while it still works.
     */
    public function imageUrl(): ?string
    {
        if ($this->stored_image_path !== null) {
            return Storage::disk((string) config('filesystems.image_disk', 'public'))
                ->url($this->stored_image_path);
        }

        // Pixelforge's signed URL is only useful until it expires. Once it has, return
        // null so the UI shows "No image" instead of a permanently broken thumbnail.
        if ($this->image_url_expires_at !== null && $this->image_url_expires_at->isPast()) {
            return null;
        }

        return $this->image_url;
    }

    public static function rank(?string $status): int
    {
        return self::STATUS_RANK[$status] ?? -1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_active' => ! $this->isTerminal(),
            'is_terminal' => $this->isTerminal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?? [],
            'image_url' => $this->imageUrl(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'estimated_cost' => Money::toDecimal($this->estimated_charged_nano),
            'estimated_cost_display' => Money::display($this->estimated_charged_nano),
            'actual_cost' => $this->charged_nano === null ? null : Money::toDecimal($this->charged_nano),
            'actual_cost_display' => $this->charged_nano === null ? null : Money::display($this->charged_nano),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
