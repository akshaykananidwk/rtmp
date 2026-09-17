<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['platform', 'event_type', 'signature_status', 'payload', 'ip_address', 'processed', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed' => 'boolean', 'created_at' => 'datetime'];
    }
}
