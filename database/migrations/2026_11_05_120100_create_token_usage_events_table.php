<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only ledger of every change to a member's metered token usage. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // period_start | usage | extra_grant | adjustment
            $table->string('type', 20);
            // Weighted tokens; negative for usage.
            $table->bigInteger('tokens');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Share of the period allowance at the time, so history reads in percent.
            $table->decimal('percent', 7, 3)->default(0);
            $table->string('reservation_id', 40)->nullable();
            $table->uuid('ai_question_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Member usage history (also serves the user_id FK).
            $table->index(['user_id', 'created_at'], 'tue_user_created_idx');
            // A reservation is charged once.
            $table->unique('reservation_id', 'tue_reservation_unique');
            // Admin overview: type = usage and created_at >= 14 days ago.
            $table->index(['type', 'created_at'], 'tue_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_usage_events');
    }
};
