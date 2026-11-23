<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->timestamp('submission_recovery_attempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('submission_recovery_attempted_at');
        });
    }
};
