<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;

class ReferralRedemption extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['referral_code_id', 'user_id'];
}
