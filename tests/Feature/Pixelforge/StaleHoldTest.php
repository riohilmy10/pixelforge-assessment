<?php

namespace Tests\Feature\Pixelforge;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class StaleHoldTest extends TestCase
{
    use PixelforgeTestHelpers, RefreshDatabase;

    public function test_stale_ambiguous_holds_are_released_but_recent_ones_are_kept(): void
    {
        $held = 10_000_000;
        $user = User::factory()->withCredits(1_000_000_000)->create();
        $user->forceFill(['reserved_nano' => $held * 2])->save();

        $old = $this->ambiguousGeneration($user, $held, now()->subHours(25));
        $recent = $this->ambiguousGeneration($user, $held, now()->subHour());

        $this->artisan('pixelforge:release-stale-holds', ['--hours' => 24])
            ->assertSuccessful();

        $old->refresh();
        $recent->refresh();
        $user->refresh();

        $this->assertSame(0, $old->reserved_nano);
        $this->assertSame('submit_expired', $old->error_code);
        $this->assertStringContainsString('hold was released', $old->error_message);

        $this->assertSame($held, $recent->reserved_nano);
        $this->assertSame('submit_failed', $recent->error_code);

        $this->assertSame($held, $user->reserved_nano);
        $this->assertSame(1_000_000_000, $user->credit_balance_nano);

        $this->assertSame(1, CreditTransaction::query()
            ->where('generation_id', $old->id)
            ->where('type', CreditTransaction::TYPE_RELEASE)
            ->count());
        $this->assertSame(0, CreditTransaction::query()
            ->where('generation_id', $recent->id)
            ->count());
    }

    public function test_the_reconciliation_sweep_leaves_rows_older_than_its_bound_alone(): void
    {
        Http::fake();

        $generation = $this->ambiguousGeneration(
            User::factory()->create(),
            10_000_000,
            now()->subHours(25),
        );

        $this->artisan('pixelforge:reconcile-ambiguous-submissions')
            ->assertSuccessful();

        Http::assertNothingSent();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('submit_failed', $generation->error_code);
        $this->assertNull($generation->submission_recovery_attempted_at);
    }

    private function ambiguousGeneration(User $user, int $held, mixed $attemptedAt): Generation
    {
        $generation = $this->createGeneration($user, [
            'reserved_nano' => $held,
        ]);

        $generation->forceFill([
            'status' => Generation::STATUS_FAILED,
            'error_code' => 'submit_failed',
            'error_message' => 'The connection was lost.',
            'submission_attempted_at' => $attemptedAt,
        ])->save();

        return $generation;
    }
}
