<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthCheck extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['run_id', 'service', 'status', 'message', 'details', 'duration_ms', 'trigger', 'created_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }
}
