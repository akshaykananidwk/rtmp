<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    protected $fillable = ['reference', 'tenant_id', 'user_id', 'request_id', 'module', 'exception_class', 'message', 'file', 'line', 'method', 'url', 'ip_address', 'trace', 'resolved'];

    protected function casts(): array
    {
        return ['resolved' => 'boolean'];
    }
}
