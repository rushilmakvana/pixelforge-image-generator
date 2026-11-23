<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only ledger. A user's balance is the sum of amount_nanos.
     *
     *   purchase  +X   bought credits
     *   hold      -max reserved when a generation is submitted
     *   release   +max hold returned when the generation finishes (either way)
     *   charge    -actual cost + markup, only for succeeded generations
     *
     * The unique (generation_id, type) index is the hard guarantee that a
     * generation can never be charged, held or released twice — even if two
     * webhook deliveries race each other.
     */
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nanos');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
