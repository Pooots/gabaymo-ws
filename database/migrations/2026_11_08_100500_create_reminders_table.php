<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->timestamp('due_at');
            $table->text('notes')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->timestamp('notified_day_before_at')->nullable();
            $table->timestamp('notified_due_at')->nullable();
            $table->timestamps();

            // Member list: where user_id orderBy due_at (also serves the FK).
            $table->index(['user_id', 'due_at']);
            // Reminder sweep: notified_due_at is null and due_at in [today, tomorrow end).
            $table->index(['notified_due_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
