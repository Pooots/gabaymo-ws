<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;

class AccessKeyRedemption extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['access_key_id', 'user_id'];
}
