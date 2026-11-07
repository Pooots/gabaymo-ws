<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvite extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['organization_id', 'email'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
