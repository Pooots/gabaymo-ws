<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group plans: a school or division pays for a number of member seats.
 * Runs before users: users.organization_id references organizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // school | division | other
            $table->string('type', 20);
            $table->string('region', 120)->nullable();
            $table->unsignedInteger('seats');
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->timestamp('period_end');
            $table->timestamps();

            $table->index('name');
        });

        // Invited emails that do not have an account yet; claimed at registration.
        Schema::create('organization_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
            // Claim at registration: where email = ? (no organization known yet).
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invites');
        Schema::dropIfExists('organizations');
    }
};
