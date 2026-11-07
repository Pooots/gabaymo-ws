<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runs before organizations and users: both reference it.
     *
     * Plans carry a weighted-token allowance per usage period (monthly for
     * paid plans, once for the free trial).
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price')->default(0);
            $table->string('cadence', 20);
            $table->unsignedBigInteger('token_allowance')->default(0);
            // month: refreshes every month (yearly plans too) | once: never refreshes.
            $table->string('allowance_period', 10)->default('month');
            $table->string('summary');
            $table->json('specs');
            $table->boolean('featured')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Catalog / overview / free-plan lookup: where active orderBy sort_order.
            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
