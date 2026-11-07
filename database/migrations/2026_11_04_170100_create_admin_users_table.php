<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff accounts for admin.gabaymo.com. Kept apart from customers and
     * partners (users table) so no customer data or role name can grant
     * admin access.
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('email')->unique();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            // invited (no password yet; cannot sign in) | active
            $table->string('account_status', 20)->default('active');
            // Set by another staff member; a suspended account cannot sign in.
            $table->timestamp('suspended_at')->nullable();
            // Null until an invited account sets one.
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();

            // Two-factor sign-in and lockout.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            // Bumped to sign out every session (password change or reset).
            $table->unsignedInteger('session_version')->default(0);
            $table->timestamps();

            // Role page account lists: where role_id orderBy name (also serves the FK).
            $table->index(['role_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
