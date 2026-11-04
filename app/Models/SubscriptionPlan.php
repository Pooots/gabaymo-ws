<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'price',
        'cadence',
        'tokens',
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
            'tokens' => 'integer',
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

    public function priceLabel(): string
    {
        return '₱'.number_format($this->price);
    }
}
