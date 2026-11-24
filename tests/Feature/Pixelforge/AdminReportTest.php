<?php

namespace Tests\Feature\Pixelforge;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use PixelforgeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
            'services.pixelforge.api_key' => 'sk_test',
            'services.pixelforge.webhook_secret' => 'whsec_test',
        ]);

        Cache::flush();
    }

    public function test_guests_are_redirected_and_non_admins_are_forbidden(): void
    {
        $this->get(route('admin.report'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('admin.report'))
            ->assertForbidden();
    }

    public function test_the_report_aggregates_by_day_and_user_and_reconciles_with_usage(): void
    {
        Http::fake([
            '*/usage*' => Http::response($this->usagePayload()),
        ]);

        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->succeededGeneration($alice, '2026-11-22T12:00:00Z', 100_000_000, 80_000_000);
        $this->succeededGeneration($bob, '2026-11-22T18:00:00Z', 50_000_000, 40_000_000);

        // Not billed: a failed generation is invisible to the report.
        $this->createGeneration($alice, [
            'status' => Generation::STATUS_FAILED,
            'completed_at' => '2026-11-22T19:00:00Z',
            'charged_nano' => null,
        ]);

        // Outside the requested window.
        $this->succeededGeneration($bob, '2026-11-20T12:00:00Z', 900_000_000, 900_000_000);

        $this->actingAs($admin)
            ->get(route('admin.report', [
                'start_date' => '2026-11-21',
                'end_date' => '2026-11-23',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->where('report.period.start_date', '2026-11-21')
                ->where('report.period.end_date', '2026-11-23')
                ->where('report.totals.generations', 2)
                ->where('report.totals.charged_nano', 150_000_000)
                ->where('report.totals.charged_display', '$0.15')
                ->where('report.totals.provider_cost_nano', 120_000_000)
                ->where('report.totals.profit_nano', 30_000_000)
                ->where('report.totals.provider_reported_cost_nano', 120_000_000)
                ->where('report.totals.difference_nano', 0)
                ->where('report.totals.provider_matches', true)
                ->where('report.provider.available', true)
                ->has('report.days', 3)
                // Newest day first.
                ->where('report.days.1.date', '2026-11-22')
                ->where('report.days.1.generations', 2)
                ->where('report.days.1.charged_display', '$0.15')
                ->where('report.days.1.provider_reported_cost_nano', 120_000_000)
                ->where('report.days.1.matches', true)
                ->has('report.users', 2)
                ->where('report.users.0.name', 'Alice')
                ->where('report.users.0.charged_display', '$0.1')
                ->where('report.users.1.name', 'Bob')
                ->where('report.users.1.charged_display', '$0.05')
            );
    }

    public function test_a_matching_total_does_not_hide_a_day_level_mismatch(): void
    {
        Http::fake([
            '*/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-23',
                'data' => [
                    ['date' => '2026-11-22', 'generations' => 0, 'cost_usd' => '0.000000000'],
                    ['date' => '2026-11-23', 'generations' => 1, 'cost_usd' => '0.080000000'],
                ],
                'total' => ['generations' => 1, 'cost_usd' => '0.080000000'],
            ]),
        ]);

        $admin = User::factory()->admin()->create();
        $this->succeededGeneration($admin, '2026-11-22T12:00:00Z', 100_000_000, 80_000_000);

        $this->actingAs($admin)
            ->get(route('admin.report', [
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-23',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->where('report.totals.provider_cost_nano', 80_000_000)
                ->where('report.totals.provider_reported_cost_nano', 80_000_000)
                ->where('report.totals.difference_nano', 0)
                // Totals agree, but the days do not, so the operator should still see a warning.
                ->where('report.totals.provider_matches', false)
                ->where('report.days.0.date', '2026-11-23')
                ->where('report.days.0.matches', false)
                ->where('report.days.1.date', '2026-11-22')
                ->where('report.days.1.matches', false)
            );
    }

    public function test_apply_can_bypass_the_short_usage_cache(): void
    {
        Cache::flush();
        Http::fake([
            '*/usage*' => Http::response($this->usagePayload()),
        ]);

        $admin = User::factory()->admin()->create();
        $query = [
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-23',
        ];

        $this->actingAs($admin)->get(route('admin.report', $query))->assertOk();
        $this->actingAs($admin)->get(route('admin.report', $query))->assertOk();
        Http::assertSentCount(1);

        $this->actingAs($admin)
            ->get(route('admin.report', [...$query, 'refresh' => 1]))
            ->assertOk();
        Http::assertSentCount(2);
    }

    public function test_the_report_uses_the_actual_settled_charge_when_it_differs_from_the_nominal_charge(): void
    {
        Http::fake([
            '*/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-22',
                'data' => [
                    ['date' => '2026-11-22', 'generations' => 1, 'cost_usd' => '0.080000000'],
                ],
                'total' => ['generations' => 1, 'cost_usd' => '0.080000000'],
            ]),
        ]);

        $admin = User::factory()->admin()->create();
        $this->createGeneration($admin, [
            'status' => Generation::STATUS_SUCCEEDED,
            'completed_at' => '2026-11-22T12:00:00Z',
            'charged_nano' => 100_000_000,
            'settled_charge_nano' => 50_000_000,
            'provider_cost_nano' => 80_000_000,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.report', [
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-22',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                // The user was actually debited 50M, even though the nominal charge is 100M.
                ->where('report.totals.charged_nano', 50_000_000)
                ->where('report.totals.provider_cost_nano', 80_000_000)
                ->where('report.totals.profit_nano', -30_000_000)
                ->where('report.users.0.charged_nano', 50_000_000)
            );
    }

    public function test_provider_matches_requires_both_the_days_and_the_total_to_agree(): void
    {
        Http::fake([
            '*/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-22',
                // The provider's own day row and total disagree; the report must not
                // show a green match just because the day row agrees with us.
                'data' => [
                    ['date' => '2026-11-22', 'generations' => 1, 'cost_usd' => '0.080000000'],
                ],
                'total' => ['generations' => 1, 'cost_usd' => '0.090000000'],
            ]),
        ]);

        $admin = User::factory()->admin()->create();
        $this->succeededGeneration($admin, '2026-11-22T12:00:00Z', 100_000_000, 80_000_000);

        $this->actingAs($admin)
            ->get(route('admin.report', [
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-22',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->where('report.days.0.matches', true)
                ->where('report.totals.difference_nano', -10_000_000)
                ->where('report.totals.provider_matches', false)
            );
    }

    public function test_a_usage_row_without_a_cost_makes_reconciliation_unavailable(): void
    {
        Http::fake([
            '*/usage*' => Http::response([
                'object' => 'usage',
                'data' => [
                    ['date' => '2026-11-24', 'generations' => 1],
                ],
                'total' => ['generations' => 1, 'cost_usd' => '0.000000000'],
            ]),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.provider.available', false)
                ->where('report.provider.error', 'Pixelforge usage could not be loaded. Local records are still shown.')
                ->where('report.totals.provider_matches', false)
            );
    }

    public function test_an_empty_usage_range_is_a_valid_zero(): void
    {
        Http::fake([
            '*/usage*' => Http::response([
                'object' => 'usage',
                'data' => [],
                'total' => ['generations' => 0, 'cost_usd' => '0.000000000'],
            ]),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.provider.available', true)
                ->where('report.totals.provider_reported_cost_nano', 0)
                ->where('report.totals.provider_matches', true)
            );
    }

    public function test_a_provider_usage_failure_degrades_without_breaking_the_report(): void
    {
        Http::fake([
            '*/usage*' => Http::response([
                'error' => ['code' => 'overloaded', 'message' => 'Busy.'],
            ], 503),
        ]);

        $admin = User::factory()->admin()->create();
        $this->succeededGeneration($admin, '2026-11-22T12:00:00Z', 100_000_000, 80_000_000);

        $this->actingAs($admin)
            ->get(route('admin.report', [
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-22',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->where('report.provider.available', false)
                ->where('report.provider.error', 'Pixelforge usage could not be loaded. Local records are still shown.')
                ->where('report.totals.provider_cost_nano', 80_000_000)
                ->where('report.totals.provider_reported_cost_nano', null)
                ->where('report.totals.provider_matches', false)
            );
    }

    public function test_the_range_is_limited_to_the_documented_usage_window(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.report', [
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
            ]))
            ->assertSessionHasErrors('end_date');
    }

    public function test_registration_never_grants_operator_access(): void
    {
        $creator = app(CreateNewUser::class);

        $first = $creator->create([
            'name' => 'First',
            'email' => 'first@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $second = $creator->create([
            'name' => 'Second',
            'email' => 'second@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertFalse($first->fresh()->is_admin);
        $this->assertFalse($second->fresh()->is_admin);
    }

    public function test_an_existing_account_can_be_promoted_from_the_console(): void
    {
        $user = User::factory()->create();

        $this->artisan('pixelforge:make-admin', ['email' => $user->email])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_admin);
    }

    private function succeededGeneration(User $user, string $completedAt, int $charged, int $provider): Generation
    {
        return $this->createGeneration($user, [
            'status' => Generation::STATUS_SUCCEEDED,
            'completed_at' => CarbonImmutable::parse($completedAt),
            'charged_nano' => $charged,
            'provider_cost_nano' => $provider,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function usagePayload(): array
    {
        return [
            'object' => 'usage',
            'start_date' => '2026-11-21',
            'end_date' => '2026-11-23',
            'data' => [
                [
                    'date' => '2026-11-21',
                    'generations' => 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => '0.000000000',
                ],
                [
                    'date' => '2026-11-22',
                    'generations' => 2,
                    'input_text_tokens' => 100,
                    'input_image_tokens' => 0,
                    'output_tokens' => 2200,
                    'reasoning_tokens' => 400,
                    'cost_usd' => '0.120000000',
                ],
                [
                    'date' => '2026-11-23',
                    'generations' => 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => '0.000000000',
                ],
            ],
            'total' => [
                'generations' => 2,
                'input_text_tokens' => 100,
                'input_image_tokens' => 0,
                'output_tokens' => 2200,
                'reasoning_tokens' => 400,
                'cost_usd' => '0.120000000',
            ],
        ];
    }
}
