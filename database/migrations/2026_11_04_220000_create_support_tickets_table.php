<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('open');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // Member ticket list: user_id, newest first.
            $table->index(['user_id', 'created_at']);
            // Open, unassigned queue + open counts.
            $table->index(['status', 'partner_id', 'created_at']);
            // Partner's accepted tickets, latest('accepted_at').
            $table->index(['partner_id', 'status', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
