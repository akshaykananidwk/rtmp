<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recording extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'stream_session_id', 'title', 'disk', 'path', 'format', 'resolution', 'duration_seconds', 'size_bytes', 'status', 'started_at', 'ended_at', 'expires_at'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class, 'stream_session_id');
    }
}
