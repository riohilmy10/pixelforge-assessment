<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ever webhook delivery we accepted, keyed by Pixelforge's event id so a redelivery
 * can never be processed twice. Row creation is the dedupe: `insertOrIgnore` on the
 * unique event id decides whether this delivery is new.
 *
 * @property string $pixelforge_event_id
 * @property string $type
 * @property string|null $generation_pixelforge_id
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
#[Fillable([
    'pixelforge_event_id',
    'type',
    'generation_pixelforge_id',
    'payload',
    'received_at',
    'processed_at',
])]
class WebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
