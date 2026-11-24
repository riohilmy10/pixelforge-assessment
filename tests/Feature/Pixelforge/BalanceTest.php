<?php

namespace Tests\Feature\Pixelforge;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditService;
use App\Services\GenerationWriter;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class BalanceTest extends TestCase
{
    use PixelforgeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
            'services.pixelforge.api_key' => 'sk_test',
            'services.pixelforge.webhook_secret' => 'whsec_test',
            'services.pixelforge.markup_percent' => 25,
        ]);
    }

    public function test_guests_cannot_see_or_buy_credits(): void
    {
        $this->get(route('credits.index'))->assertRedirect(route('login'));
        $this->post(route('credits.purchase'), ['amount_nano' => 5_000_000_000])
            ->assertRedirect(route('login'));
    }

    public function test_a_purchase_adds_credits_and_writes_the_ledger(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.purchase'), ['amount_nano' => 5_000_000_000])
            ->assertRedirect(route('credits.index'))
            ->assertSessionHas('status');

        $user->refresh();

        $this->assertSame(5_000_000_000, $user->credit_balance_nano);
        $this->assertSame(0, $user->reserved_nano);
        $this->assertSame(5_000_000_000, $user->availableNano());

        $transaction = CreditTransaction::query()->sole();

        $this->assertSame(CreditTransaction::TYPE_PURCHASE, $transaction->type);
        $this->assertSame(5_000_000_000, $transaction->amount_nano);
        $this->assertSame(5_000_000_000, $transaction->balance_after_nano);
    }

    public function test_only_preset_purchase_amounts_are_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('credits.purchase'), ['amount_nano' => 1])
            ->assertSessionHasErrors('amount_nano');

        $this->assertSame(0, $user->fresh()->credit_balance_nano);
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_the_credits_page_shows_the_balance_and_ledger(): void
    {
        $user = User::factory()->withCredits()->create();

        app(CreditService::class)->purchase($user, 5_000_000_000);

        $this->actingAs($user->refresh())
            ->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Credits/Index')
                ->where('balance.available_nano', 15_000_000_000)
                ->where('balance.available_display', '$15')
                ->has('transactions', 1)
                ->where('transactions.0.type', CreditTransaction::TYPE_PURCHASE)
                ->has('presets', 3)
            );
    }

    public function test_a_generation_cannot_start_without_enough_available_credits(): void
    {
        $this->fakePricing();
        Queue::fake();

        $estimate = app(PricingService::class)->estimate('A lighthouse', '1024x1024', 'medium');
        $quotedMax = Money::toDecimal($estimate->maxChargedNano);

        // Exactly one hold fits, so the second submit must be refused atomically.
        $user = User::factory()->withCredits($estimate->maxChargedNano)->create();
        $payload = [
            'quoted_max' => $quotedMax,
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ];

        $this->actingAs($user)->post(route('generations.store'), $payload)
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, Generation::query()->count());
        $this->assertSame($estimate->maxChargedNano, $user->fresh()->reserved_nano);
        $this->assertSame(0, $user->fresh()->availableNano());

        $this->actingAs($user->fresh())
            ->from(route('dashboard'))
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->assertSame(1, Generation::query()->count());
        $this->assertSame($estimate->maxChargedNano, $user->fresh()->reserved_nano);
    }

    public function test_success_settles_the_hold_and_charges_exactly_once(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->withCredits(1_000_000_000)->create();
        $generation = $this->submitGeneration($user);
        $held = $generation->fresh()->reserved_nano;

        $this->assertGreaterThan(0, $held);

        app(GenerationWriter::class)->apply(
            $generation->fresh(),
            $this->succeededObject($generation->id),
        );

        $generation->refresh();
        $user->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertSame(0, $user->reserved_nano);
        $this->assertSame($this->expectedChargedNano(), $generation->charged_nano);
        $this->assertSame($this->expectedChargedNano(), $generation->settled_charge_nano);
        $this->assertSame(1_000_000_000 - $this->expectedChargedNano(), $user->credit_balance_nano);
        $this->assertSame(-$this->expectedChargedNano(), CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->sole()
            ->amount_nano);
        $this->assertSame(
            [CreditTransaction::TYPE_RESERVE, CreditTransaction::TYPE_RELEASE, CreditTransaction::TYPE_CHARGE],
            CreditTransaction::query()
                ->where('generation_id', $generation->id)
                ->orderBy('id')
                ->pluck('type')
                ->all(),
        );

        // A duplicate success event is a no-op, including at the money level.
        app(GenerationWriter::class)->apply(
            $generation->fresh(),
            $this->succeededObject($generation->id),
        );

        $this->assertSame(1_000_000_000 - $this->expectedChargedNano(), $user->fresh()->credit_balance_nano);
        $this->assertSame(1, CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->count());
    }

    public function test_settling_the_same_generation_twice_does_not_change_the_money(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->withCredits(1_000_000_000)->create();
        $generation = $this->submitGeneration($user);

        app(GenerationWriter::class)->apply(
            $generation->fresh(),
            $this->succeededObject($generation->id),
        );

        $settled = $generation->fresh()->settled_charge_nano;
        $balance = $user->fresh()->credit_balance_nano;

        // The writer is terminal-guarded, but the credit service must also be safe if a
        // retry reaches it directly.
        app(CreditService::class)->settle($generation->fresh());
        app(CreditService::class)->settle($generation->fresh());

        $this->assertSame($settled, $generation->fresh()->settled_charge_nano);
        $this->assertSame($balance, $user->fresh()->credit_balance_nano);
        $this->assertSame(1, CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->count());
    }

    public function test_a_failed_generation_releases_the_whole_hold(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->withCredits(1_000_000_000)->create();
        $generation = $this->submitGeneration($user);

        app(GenerationWriter::class)->apply(
            $generation->fresh(),
            $this->generationObject([
                'id' => 'gen_failed',
                'status' => Generation::STATUS_FAILED,
                'metadata' => ['generation_id' => $generation->id],
                'error' => ['code' => 'generation_failed', 'message' => 'The model failed.'],
                'completed_at' => '2026-11-22T00:01:00Z',
            ]),
        );

        $generation->refresh();
        $user->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertSame(0, $user->reserved_nano);
        $this->assertSame(1_000_000_000, $user->credit_balance_nano);
        $this->assertNull($generation->charged_nano);
        $this->assertSame(1, CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::TYPE_RELEASE)
            ->count());
        $this->assertSame(0, CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->count());
    }

    public function test_an_ambiguous_submit_keeps_the_hold_until_a_late_success_settles_it(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->withCredits(1_000_000_000)->create();
        $generation = $this->submitGeneration($user);
        $held = $generation->fresh()->reserved_nano;

        app(GenerationWriter::class)->failLocal($generation->fresh(), 'submit_failed', 'The response was lost.');

        $this->assertSame(Generation::STATUS_FAILED, $generation->fresh()->status);
        $this->assertSame($held, $user->fresh()->reserved_nano);

        app(GenerationWriter::class)->apply(
            $generation->fresh(),
            $this->succeededObject($generation->id),
        );

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->fresh()->status);
        $this->assertSame(0, $user->fresh()->reserved_nano);
        $this->assertSame(
            1_000_000_000 - $this->expectedChargedNano(),
            $user->fresh()->credit_balance_nano,
        );
    }

    public function test_legacy_generations_without_a_hold_are_not_retroactively_charged(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->withCredits(1_000_000_000)->create();
        $generation = $this->createGeneration($user, ['reserved_nano' => 0]);

        app(GenerationWriter::class)->apply(
            $generation,
            $this->succeededObject($generation->id),
        );

        $this->assertSame(1_000_000_000, $user->fresh()->credit_balance_nano);
        $this->assertSame($this->expectedChargedNano(), $generation->fresh()->charged_nano);
        $this->assertSame($this->expectedChargedNano(), $generation->fresh()->settled_charge_nano);
        $this->assertSame(0, CreditTransaction::query()->where('generation_id', $generation->id)->count());
    }

    public function test_settlement_is_capped_at_the_held_quote_and_absorbs_the_overage(): void
    {
        $this->fakePricing();
        Queue::fake();

        $user = User::factory()->withCredits(1_000_000_000)->create();
        $held = 1_000_000;

        // Simulate a hold whose quote is far below the eventual provider cost.
        $user->forceFill(['reserved_nano' => $held])->save();

        $generation = $this->createGeneration($user, [
            'reserved_nano' => $held,
            'estimated_charged_nano' => $held,
        ]);

        app(GenerationWriter::class)->apply(
            $generation,
            $this->succeededObject($generation->id),
        );

        $generation->refresh();
        $user->refresh();

        $this->assertSame($this->expectedProviderCostNano(), $generation->charged_nano);
        $this->assertSame($held, $generation->settled_charge_nano);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertSame(0, $user->reserved_nano);
        $this->assertSame(1_000_000_000 - $held, $user->credit_balance_nano);
        $this->assertGreaterThanOrEqual(0, $user->availableNano());

        $charge = CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->sole();

        $this->assertSame(-$held, $charge->amount_nano);
    }

    /**
     * Submit through the real endpoint so the reserve path is exercised, while the
     * queued provider call stays faked.
     */
    private function submitGeneration(User $user): Generation
    {
        $estimate = app(PricingService::class)->estimate('A lighthouse', '1024x1024', 'medium');

        $this->actingAs($user)->post(route('generations.store'), [
            'quoted_max' => Money::toDecimal($estimate->maxChargedNano),
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect(route('dashboard'));

        return Generation::query()->latest('created_at')->firstOrFail();
    }
}
