<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments with receipts, failed/refunded/disputed states, the period each
 * payment bought (so a refund can take it back), and a webhook event log
 * that makes PayMongo retries harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->string('receipt_number', 40)->nullable()->unique();
            // Webhook / confirm lookups by each PayMongo id (one payment per id).
            $table->string('paymongo_checkout_id')->nullable()->unique();
            $table->string('paymongo_payment_intent_id')->nullable()->unique();
            $table->string('paymongo_payment_id')->nullable()->unique();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PHP');
            // pending | paid | failed | refunded | partially_refunded | disputed
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->timestamp('disputed_at')->nullable();
            // Paid period end before and after this payment was applied.
            $table->timestamp('period_end_before')->nullable();
            $table->timestamp('period_end_after')->nullable();
            $table->boolean('changed_plan')->default(false);
            $table->string('failure_reason', 200)->nullable();
            $table->timestamp('receipt_sent_at')->nullable();
            $table->timestamps();

            // Status counts / filter, and revenue: status in (paid, ...) and paid_at >= start of month.
            $table->index(['status', 'paid_at']);
            // Member payment history (also serves the user_id FK).
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('paymongo_webhook_events', function (Blueprint $table) {
            $table->id();
            // Idempotency: one row per PayMongo event.
            $table->string('event_id')->unique();
            $table->string('type', 80);
            // Refund de-duplication: where resource_id = ? and outcome like 'refund%'.
            $table->string('resource_id')->nullable()->index();
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymongo_webhook_events');
        Schema::dropIfExists('subscription_payments');
    }
};
