<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;

/** Processed PayMongo webhook events; a repeated event id is acknowledged and skipped. */
class PaymongoWebhookEvent extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['event_id', 'type', 'resource_id', 'subscription_payment_id', 'outcome'];
}
