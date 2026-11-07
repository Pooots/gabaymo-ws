<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers (members) and partners. Staff live in admin_users, never here.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Ordered lists (organization members, role pages).
            $table->string('name')->index();
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('email')->unique();
            $table->string('school_name')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('region', 120)->nullable();

            // customer | partner
            $table->string('account_type', 20)->default('customer');
            // invited (admin created it, no password yet; cannot sign in) | active
            $table->string('account_status', 20)->default('active');

            // Partner profile.
            $table->string('title', 120)->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_url', 2048)->nullable();
            $table->string('specialty', 160)->nullable();
            $table->unsignedSmallInteger('response_hours')->default(24);
            $table->string('partner_status', 20)->default('active');
            // Partner practice areas (see App\Support\PracticeAreas). Empty = takes every area.
            $table->json('practice_areas')->nullable();

            // Plan and subscription.
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // trial | active | cancelled | expired
            $table->string('subscription_status', 20)->default('trial');
            // personal (paid) | organization | admin | access_key | trial
            $table->string('plan_source', 20)->default('trial');
            // End of the paid period. Null on the free trial.
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('renewal_reminded_7d_at')->nullable();
            $table->timestamp('renewal_reminded_1d_at')->nullable();

            // Metered token usage: the current period's allowance, what was used,
            // what in-flight questions hold, and staff-granted extra usage (carries over).
            $table->unsignedBigInteger('token_allowance')->default(0);
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->unsignedBigInteger('tokens_reserved')->default(0);
            $table->unsignedBigInteger('bonus_tokens')->default(0);
            $table->timestamp('usage_period_started_at')->nullable();
            $table->timestamp('usage_period_ends_at')->nullable();

            // Lifecycle and security.
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 300)->nullable();
            // Bumped to sign out every session (password change, logout-all, suspension).
            $table->unsignedInteger('session_version')->default(0);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->boolean('issuance_alerts')->default(true);

            $table->timestamp('email_verified_at')->nullable();
            // Null until an invited account sets one.
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Member / partner lists newest first, sign-up stats by date range.
            $table->index(['account_type', 'created_at']);
            // Admin member filter: customers where subscription_status = ?.
            $table->index(['account_type', 'subscription_status'], 'users_type_subscription_idx');
            // Expiry sweep + renewal reminders: customers where current_period_end <= / between.
            $table->index(['account_type', 'current_period_end'], 'users_type_period_end_idx');
            // Monthly allowance roll-over sweep: customers where usage_period_ends_at <= now.
            $table->index(['account_type', 'usage_period_ends_at'], 'users_type_usage_end_idx');
            // Issuance alerts: customers grouped / filtered by region.
            $table->index(['account_type', 'region'], 'users_type_region_idx');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
