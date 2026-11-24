<?php

namespace App\Http\Requests;

use App\Services\CreditService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A fake top-up. Only the preset amounts are accepted, so the client cannot invent a
 * balance by sending an arbitrary number.
 */
class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount_nano' => ['required', 'integer', Rule::in(CreditService::PURCHASE_PRESETS_NANO)],
        ];
    }

    public function amountNano(): int
    {
        return (int) $this->validated('amount_nano');
    }
}
