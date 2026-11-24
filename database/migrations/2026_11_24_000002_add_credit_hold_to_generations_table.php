<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            // The worst-case quote actually held against the user's balance. It is zero
            // for rows created before balances existed, which keeps settlement from
            // retroactively charging those generations.
            $table->bigInteger('reserved_nano')->default(0);

            // Operator reports group by completed_at date.
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropColumn('reserved_nano');
        });
    }
};
