<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamSessionDestination extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'stream_session_id', 'stream_destination_id', 'desired_state', 'status', 'node_id', 'pid', 'retry_count', 'next_retry_at', 'started_at', 'ended_at', 'last_success_at', 'last_error', 'last_error_at', 'bytes_sent', 'outgoing_bitrate_kbps', 'platform_meta'];

    protected function casts(): array
    {
        return [
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'platform_meta' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class, 'stream_session_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(StreamDestination::class, 'stream_destination_id');
    }

    public function wantsToRun(): bool
    {
        return $this->desired_state === 'running';
    }
}
