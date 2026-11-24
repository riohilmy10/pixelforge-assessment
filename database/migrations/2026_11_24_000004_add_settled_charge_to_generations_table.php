<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            // What was actually debited from the user's credits. The nominal `charged_nano`
            // can exceed the held quote when provider rates change under us; the difference
            // is an absorbed overage, so the quote remains the hard cap for the balance.
            // Nullable so pre-balance rows fall back to `charged_nano` in reads.
            $table->bigInteger('settled_charge_nano')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropColumn('settled_charge_nano');
        });
    }
};
