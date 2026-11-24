<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in a user's credit ledger.
 *
 * `amount_nano` is the signed change to the *spendable* amount (balance minus holds), so
 * reserve entries are negative even though the owned balance has not changed. The running
 * `balance_after_nano` is that same spendable amount, which is what the credits page
 * shows. `reserved_after_nano` keeps the two parts auditable side by side.
 *
 * @property int $user_id
 * @property string|null $generation_id
 * @property string $type
 * @property int $amount_nano
 * @property int $balance_after_nano
 * @property int $reserved_after_nano
 * @property string|null $description
 */
#[Fillable([
    'user_id',
    'generation_id',
    'type',
    'amount_nano',
    'balance_after_nano',
    'reserved_after_nano',
    'description',
])]
class CreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_RESERVE = 'reserve';

    public const TYPE_RELEASE = 'release';

    public const TYPE_CHARGE = 'charge';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_nano' => 'integer',
            'balance_after_nano' => 'integer',
            'reserved_after_nano' => 'integer',
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
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * Statement line: signed amount, the running spendable balance, and a plain-English
     * reason so a user can tell a hold apart from an actual charge.
     *
     * @return array<string, mixed>
     */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => match ($this->type) {
                self::TYPE_PURCHASE => 'Credits purchased',
                self::TYPE_RESERVE => 'Hold for generation',
                self::TYPE_RELEASE => 'Hold released',
                self::TYPE_CHARGE => 'Generation charged',
                default => ucfirst($this->type),
            },
            'description' => $this->description,
            'amount_nano' => $this->amount_nano,
            'amount' => Money::toDecimal($this->amount_nano),
            'amount_display' => ($this->amount_nano > 0 ? '+' : '').Money::display($this->amount_nano),
            'balance_after_display' => Money::display($this->balance_after_nano),
            'reserved_after_display' => Money::display($this->reserved_after_nano),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
