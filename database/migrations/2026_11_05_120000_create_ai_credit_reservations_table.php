<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Weighted tokens held by an in-flight AI question until gabaymo-ai settles it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_reservations', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Weighted tokens held.
            $table->unsignedBigInteger('amount')->default(0);
            // What the question really used (set on finalize).
            $table->unsignedBigInteger('charged_tokens')->nullable();
            // reserved | finalized | released
            $table->string('status', 20)->default('reserved');
            $table->string('idempotency_key', 150);
            $table->uuid('ai_question_id')->nullable();
            $table->json('usage')->nullable();
            $table->string('reason', 120)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // Retry de-duplication (also serves the user_id FK).
            $table->unique(['user_id', 'idempotency_key'], 'acr_user_key_unique');
            // Stale-hold sweep: status = reserved and created_at <= cutoff.
            $table->index(['status', 'created_at'], 'acr_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_reservations');
    }
};
