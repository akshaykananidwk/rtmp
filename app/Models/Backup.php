<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    use HasUlids;

    protected $fillable = ['type', 'trigger', 'status', 'disk', 'location', 'db_location', 'size_bytes', 'checksum', 'db_checksum', 'encrypted', 'verified', 'app_version', 'git_commit', 'metadata', 'error', 'created_by', 'started_at', 'completed_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
            'verified' => 'boolean',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === 'completed' && $this->verified;
    }
}
