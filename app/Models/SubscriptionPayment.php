<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use StoresAppTimezone;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'reference',
        'paymongo_checkout_id',
        'amount',
        'currency',
        'status',
        'paid_at',
        'paymongo_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refunded_amount' => 'integer',
            'disputed_at' => 'datetime',
            'period_end_before' => 'datetime',
            'period_end_after' => 'datetime',
            'changed_plan' => 'boolean',
            'receipt_sent_at' => 'datetime',
        ];
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'receipt_number' => $this->receipt_number,
            'plan' => $this->plan?->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'refunded_amount' => $this->refunded_amount,
            'period_end' => $this->period_end_after?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
