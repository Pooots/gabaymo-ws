<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Partner tickets: practice-area routing, reply deadlines, AI handoffs and closing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject')->index();
            $table->text('body');
            // open | accepted | closed
            $table->string('status')->default('open');
            // See App\Support\PracticeAreas. Admin filter: where practice_area = ?.
            $table->string('practice_area', 40)->nullable()->index();
            // Links a ticket opened by an AI handoff to the AI question (idempotency).
            $table->uuid('ai_question_id')->nullable()->unique('st_ai_question_unique');
            $table->timestamp('accepted_at')->nullable();
            // When the assigned partner is expected to reply (set on accept and on each member message).
            $table->timestamp('reply_due_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // member | partner | admin
            $table->string('closed_by', 20)->nullable();
            $table->timestamps();

            // Member ticket list: user_id, newest first.
            $table->index(['user_id', 'created_at']);
            // Open, unassigned queue + open counts.
            $table->index(['status', 'partner_id', 'created_at']);
            // Partner's accepted tickets, latest('accepted_at').
            $table->index(['partner_id', 'status', 'accepted_at']);
            // Overdue sweep / admin "overdue" filter: status = accepted and reply_due_at < now.
            $table->index(['status', 'reply_due_at'], 'st_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
