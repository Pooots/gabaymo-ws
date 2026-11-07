<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use StoresAppTimezone;

    protected $fillable = [
        'name',
        'price',
        'cadence',
        'token_allowance',
        'allowance_period',
        'summary',
        'specs',
        'featured',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'token_allowance' => 'integer',
            'specs' => 'array',
            'featured' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function cadenceLabel(): string
    {
        return match ($this->cadence) {
            'month' => 'per month',
            'year' => 'per year',
            default => 'forever',
        };
    }

    public function refreshesMonthly(): bool
    {
        return $this->allowance_period === 'month';
    }

    /** Rough number of typical questions the allowance covers, for plan copy only. */
    public function estimatedQuestions(): int
    {
        return intdiv((int) $this->token_allowance, max(1, (int) config('services.usage.tokens_per_question', 10000)));
    }

    public function allowanceLabel(): string
    {
        $questions = number_format($this->estimatedQuestions());

        return $this->refreshesMonthly()
            ? "About {$questions} questions of usage each month"
            : "About {$questions} questions of usage";
    }

    public function priceLabel(): string
    {
        return '₱'.number_format($this->price);
    }
}
