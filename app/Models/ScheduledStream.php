<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduledStream extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'stream_endpoint_id', 'created_by', 'title', 'description', 'scheduled_at', 'auto_stop_at', 'timezone', 'auto_start', 'auto_stop', 'recording_enabled', 'thumbnail_path', 'notes', 'status', 'stream_session_id', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'auto_stop_at' => 'datetime',
            'auto_start' => 'boolean',
            'auto_stop' => 'boolean',
            'recording_enabled' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(StreamEndpoint::class, 'stream_endpoint_id');
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(StreamDestination::class, 'scheduled_stream_destination');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class, 'stream_session_id');
    }
}
