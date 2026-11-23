<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestamp('submission_attempted_at')->nullable();
        });

        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->timestamp('last_recovery_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropColumn('last_recovery_attempt_at');
        });

        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('submission_attempted_at');
        });
    }
};
