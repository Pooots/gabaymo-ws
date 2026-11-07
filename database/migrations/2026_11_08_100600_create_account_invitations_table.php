<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set-up / password links emailed to partners (users) and staff (admin_users).
 * Only a SHA-256 of the token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            // Lookup by the hashed token from the link.
            $table->string('token_hash', 64)->unique();
            // partner (users.id) | admin (admin_users.id); no FK since it spans two tables.
            $table->string('account_type', 20);
            $table->unsignedBigInteger('account_id');
            // setup (new account) | reset (admin-sent password link for an active account)
            $table->string('purpose', 20)->default('setup');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            // Set when a newer link replaces this one.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            // Latest open invitation per account (lists, resend invalidation).
            $table->index(['account_type', 'account_id'], 'ai_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
