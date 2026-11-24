<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            // Signed change to the spendable amount (balance minus reserved), in nano.
            $table->bigInteger('amount_nano');
            // Spendable amount and reserved amount immediately after this entry.
            $table->bigInteger('balance_after_nano');
            $table->bigInteger('reserved_after_nano');
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            // Settling or releasing a generation twice would be a money bug, so the
            // database also refuses a second entry of the same kind for one generation.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
