<?php

namespace Tests\Feature\Pixelforge;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PixelforgeTestHelpers;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use PixelforgeTestHelpers, RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    }

    public function test_history_only_lists_the_authenticated_users_generations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->createGeneration($user, ['status' => Generation::STATUS_SUCCEEDED, 'charged_nano' => 1_000]);
        $this->createGeneration($other, ['status' => Generation::STATUS_FAILED]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations/Index')
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $mine->id)
                ->where('generations.data.0.actual_cost_display', '$0.000001')
            );
    }

    public function test_dashboard_exposes_the_generator_options_and_recent_generations(): void
    {
        $user = User::factory()->create();
        $generation = $this->createGeneration($user);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('options.qualities', ['low', 'medium', 'high'])
                ->has('generations', 1)
                ->where('generations.0.id', $generation->id)
            );
    }
}
