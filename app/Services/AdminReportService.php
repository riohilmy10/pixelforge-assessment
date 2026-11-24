<?php

namespace App\Services;

use App\Http\Requests\AdminReportRequest;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operator reporting: charged spend, provider cost and profit, by UTC day and by user.
 *
 * The local side is built from succeeded generations only. `completed_at` is the
 * provider's completion timestamp, which is also the day Pixelforge bills, so the local
 * provider-cost total can be compared directly with `GET /v1/usage` for the same range.
 *
 * Provider usage is cached for a minute on normal page loads; the report's Apply control
 * passes `refresh=1` to bypass that cache when the operator wants the source of truth
 * immediately. If the provider is unavailable, the local report still renders and the
 * strip says so.
 */
class AdminReportService
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function report(CarbonImmutable $start, CarbonImmutable $end, bool $refresh = false): array
    {
        $start = $start->startOfDay();
        $end = $end->endOfDay();

        // Aggregate in SQL: the report must stay bounded by the 92-day window rather
        // than the number of generations inside it. The charge uses the actual settled
        // debit when present, falling back to the nominal charge for pre-balance rows.
        $daily = DB::table('generations')
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw("(completed_at AT TIME ZONE 'UTC')::date AS completed_date")
            ->selectRaw('COUNT(*) AS generations')
            ->selectRaw('COALESCE(SUM(COALESCE(settled_charge_nano, charged_nano)), 0) AS charge_nano')
            ->selectRaw('COALESCE(SUM(provider_cost_nano), 0) AS provider_nano')
            ->groupByRaw("(completed_at AT TIME ZONE 'UTC')::date")
            ->orderBy('completed_date')
            ->get();

        $days = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[$cursor->toDateString()] = [
                'charge_nano' => 0,
                'provider_nano' => 0,
                'generations' => 0,
            ];

            $cursor = $cursor->addDay();
        }

        $totalCharge = 0;
        $totalProvider = 0;
        $totalGenerations = 0;

        foreach ($daily as $row) {
            $date = $row->completed_date instanceof \DateTimeInterface
                ? $row->completed_date->format('Y-m-d')
                : (string) $row->completed_date;

            if (! isset($days[$date])) {
                continue;
            }

            $charge = (int) $row->charge_nano;
            $provider = (int) $row->provider_nano;
            $generations = (int) $row->generations;

            $days[$date]['charge_nano'] += $charge;
            $days[$date]['provider_nano'] += $provider;
            $days[$date]['generations'] += $generations;

            $totalCharge += $charge;
            $totalProvider += $provider;
            $totalGenerations += $generations;
        }

        $userRows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', Generation::STATUS_SUCCEEDED)
            ->whereBetween('generations.completed_at', [$start, $end])
            ->groupBy('users.id', 'users.name', 'users.email')
            ->selectRaw('users.id AS user_id, users.name, users.email')
            ->selectRaw('COUNT(*) AS generations')
            ->selectRaw('COALESCE(SUM(COALESCE(generations.settled_charge_nano, generations.charged_nano)), 0) AS charge_nano')
            ->selectRaw('COALESCE(SUM(generations.provider_cost_nano), 0) AS provider_nano')
            ->orderByDesc('charge_nano')
            ->orderBy('users.name')
            ->get();

        $providerUsage = $this->providerUsage($start, $end, $refresh);
        $dayRows = [];

        foreach ($days as $date => $day) {
            $reported = $providerUsage['available']
                ? ($providerUsage['days'][$date] ?? ['cost_nano' => 0, 'generations' => 0])
                : null;

            $difference = $reported === null ? null : $day['provider_nano'] - $reported['cost_nano'];

            $dayRows[] = [
                'date' => $date,
                'generations' => $day['generations'],
                'charged_nano' => $day['charge_nano'],
                'charged' => Money::toDecimal($day['charge_nano']),
                'charged_display' => Money::display($day['charge_nano']),
                'provider_cost_nano' => $day['provider_nano'],
                'provider_cost' => Money::toDecimal($day['provider_nano']),
                'provider_cost_display' => Money::display($day['provider_nano']),
                'profit_nano' => $day['charge_nano'] - $day['provider_nano'],
                'profit' => Money::toDecimal($day['charge_nano'] - $day['provider_nano']),
                'profit_display' => Money::display($day['charge_nano'] - $day['provider_nano']),
                'provider_reported_cost_nano' => $reported['cost_nano'] ?? null,
                'provider_reported_cost_display' => $reported === null ? null : Money::display($reported['cost_nano']),
                'provider_reported_generations' => $reported['generations'] ?? null,
                'difference_nano' => $difference,
                'difference_display' => $difference === null ? null : Money::display($difference),
                'matches' => $difference !== null && $difference === 0,
            ];
        }

        $daysMatch = $providerUsage['available']
            && collect($dayRows)->every(static fn (array $day): bool => $day['matches']);

        $dayRows = array_reverse($dayRows);

        $userRows = array_map(static function (object $user): array {
            $charge = (int) $user->charge_nano;
            $provider = (int) $user->provider_nano;
            $profit = $charge - $provider;

            return [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'generations' => (int) $user->generations,
                'charged_nano' => $charge,
                'charged' => Money::toDecimal($charge),
                'charged_display' => Money::display($charge),
                'provider_cost_nano' => $provider,
                'provider_cost' => Money::toDecimal($provider),
                'provider_cost_display' => Money::display($provider),
                'profit_nano' => $profit,
                'profit' => Money::toDecimal($profit),
                'profit_display' => Money::display($profit),
            ];
        }, $userRows->all());

        $reportedTotal = $providerUsage['total']['cost_nano'] ?? null;
        $difference = $reportedTotal === null ? null : $totalProvider - $reportedTotal;

        return [
            'period' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => (int) $start->diffInDays($end) + 1,
                'max_days' => AdminReportRequest::MAX_DAYS,
            ],
            'totals' => [
                'generations' => $totalGenerations,
                'charged_nano' => $totalCharge,
                'charged' => Money::toDecimal($totalCharge),
                'charged_display' => Money::display($totalCharge),
                'provider_cost_nano' => $totalProvider,
                'provider_cost' => Money::toDecimal($totalProvider),
                'provider_cost_display' => Money::display($totalProvider),
                'profit_nano' => $totalCharge - $totalProvider,
                'profit' => Money::toDecimal($totalCharge - $totalProvider),
                'profit_display' => Money::display($totalCharge - $totalProvider),
                'provider_reported_cost_nano' => $reportedTotal,
                'provider_reported_cost_display' => $reportedTotal === null ? null : Money::display($reportedTotal),
                'provider_reported_generations' => $providerUsage['total']['generations'] ?? null,
                'difference_nano' => $difference,
                'difference_display' => $difference === null ? null : Money::display($difference),
                'provider_matches' => $daysMatch && $difference !== null && $difference === 0,
            ],
            'days' => $dayRows,
            'users' => $userRows,
            'provider' => [
                'available' => $providerUsage['available'],
                'error' => $providerUsage['error'],
                'total' => $providerUsage['total'],
            ],
        ];
    }

    /**
     * Fetch Pixelforge's own per-day billing for the same window. Any failure degrades to
     * "reconciliation unavailable" instead of breaking the whole operator page.
     *
     * @return array{
     *     available: bool,
     *     error: string|null,
     *     days: array<string, array{cost_nano: int, generations: int}>,
     *     total: array{cost_nano: int, cost: string, cost_display: string, generations: int}|null
     * }
     */
    private function providerUsage(CarbonImmutable $start, CarbonImmutable $end, bool $refresh): array
    {
        try {
            $fetch = fn (): array => $this->client->usage($start->toDateString(), $end->toDateString());
            $payload = $refresh
                ? $fetch()
                : Cache::remember(
                    "pixelforge.usage.{$start->toDateString()}.{$end->toDateString()}",
                    now()->addMinute(),
                    $fetch,
                );
            $rows = $payload['data'] ?? null;

            if (! is_array($rows)) {
                throw new \RuntimeException('Pixelforge usage response did not include a data array.');
            }

            $days = [];

            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['date']) || ! is_string($row['date'])) {
                    continue;
                }

                $days[$row['date']] = [
                    'cost_nano' => Money::fromDecimal((string) ($row['cost_usd'] ?? '0')),
                    'generations' => (int) ($row['generations'] ?? 0),
                ];
            }

            $total = $payload['total'] ?? null;
            $costNano = is_array($total) && isset($total['cost_usd'])
                ? Money::fromDecimal((string) $total['cost_usd'])
                : array_sum(array_column($days, 'cost_nano'));
            $totalGenerations = is_array($total)
                ? (int) ($total['generations'] ?? 0)
                : array_sum(array_column($days, 'generations'));

            return [
                'available' => true,
                'error' => null,
                'days' => $days,
                'total' => [
                    'cost_nano' => $costNano,
                    'cost' => Money::toDecimal($costNano),
                    'cost_display' => Money::display($costNano),
                    'generations' => $totalGenerations,
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('pixelforge.usage_report_unavailable', [
                'message' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'error' => 'Pixelforge usage could not be loaded. Local records are still shown.',
                'days' => [],
                'total' => null,
            ];
        }
    }
}
