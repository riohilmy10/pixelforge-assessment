<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Credits are dollars in integer nanodollars. `reserved_nano` is the sum of
            // worst-case quotes held for generations that have not finished yet, so the
            // spendable amount is always `credit_balance_nano - reserved_nano`.
            $table->bigInteger('credit_balance_nano')->default(0);
            $table->bigInteger('reserved_nano')->default(0);
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['credit_balance_nano', 'reserved_nano', 'is_admin']);
        });
    }
};
