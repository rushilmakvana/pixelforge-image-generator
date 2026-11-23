<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sent as Idempotency-Key, so every retry of the submit hits the same Pixelforge generation.
            $table->uuid('idempotency_key')->unique();
            // Null until Pixelforge accepts the request (or a webhook tells us first).
            $table->string('pixelforge_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // pending (not yet accepted) | queued | processing | succeeded | failed
            $table->string('status')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            // Shown while pending, e.g. "Waiting for capacity — retrying in 5s".
            $table->string('status_note')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);

            // User-facing estimate (cost + markup), in nanodollars.
            $table->bigInteger('estimated_min_nanos');
            $table->bigInteger('estimated_nanos');
            $table->bigInteger('estimated_max_nanos');

            // Filled when the generation succeeds.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charged_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            // Pixelforge bills on the UTC day of completion; the admin report groups by this.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
