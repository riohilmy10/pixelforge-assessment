<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images')->nullable();
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('stored_image_path')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable();
            $table->bigInteger('charged_nano')->nullable();
            $table->bigInteger('estimated_provider_cost_nano')->default(0);
            $table->bigInteger('estimated_charged_nano')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
