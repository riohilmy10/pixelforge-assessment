<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Seed a normal demo account only. Operator access is provisioned explicitly with
        // `php artisan pixelforge:make-admin`, never by seeding a known-credential admin.
        User::factory()
            ->withCredits()
            ->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
    }
}
