<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceInstruction extends Model
{
    protected $fillable = [
        'title',
        'body',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
