<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The operator report window. Pixelforge caps `/v1/usage` at 92 days, so the same cap
 * applies here; the defaults are the last 30 days and are inclusive of both ends.
 */
class AdminReportRequest extends FormRequest
{
    public const MAX_DAYS = 92;

    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $start = $this->startDate();
                $end = $this->endDate();

                if ($start->greaterThan($end)) {
                    $validator->errors()->add('end_date', 'The end date must be on or after the start date.');

                    return;
                }

                if ($start->diffInDays($end) >= self::MAX_DAYS) {
                    $validator->errors()->add(
                        'end_date',
                        'The range can cover at most '.self::MAX_DAYS.' days.',
                    );
                }
            },
        ];
    }

    public function startDate(): CarbonImmutable
    {
        return $this->parseDate('start_date') ?? $this->endDate()->subDays(29);
    }

    public function endDate(): CarbonImmutable
    {
        return $this->parseDate('end_date') ?? CarbonImmutable::now('UTC')->startOfDay();
    }

    /**
     * Both dates are interpreted as UTC calendar days, matching how Pixelforge bills.
     *
     * Deliberately reads raw input rather than `validated()`: this is also called from
     * the `after()` validation hook, where the validator is still being built and
     * `validated()` would recurse. The helper is not named `date()` because Request
     * already declares a method with that name.
     */
    private function parseDate(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
