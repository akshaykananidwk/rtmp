<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StreamSession extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'stream_endpoint_id', 'scheduled_stream_id', 'title', 'status', 'node_id', 'started_at', 'distribution_started_at', 'ended_at', 'duration_seconds', 'incoming_bitrate_kbps', 'outgoing_bitrate_kbps', 'bytes_received', 'bytes_sent', 'resolution', 'fps', 'video_codec', 'audio_codec', 'recording_enabled', 'recording_path', 'branding_status', 'overlay_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'distribution_started_at' => 'datetime',
            'ended_at' => 'datetime',
            'recording_enabled' => 'boolean',
            'fps' => 'float',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(StreamEndpoint::class, 'stream_endpoint_id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(StreamSessionDestination::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StreamDestinationLog::class);
    }

    public function recording(): HasOne
    {
        return $this->hasOne(Recording::class);
    }

    public function overlay(): BelongsTo
    {
        return $this->belongsTo(Overlay::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['detected', 'live'], true);
    }

    public function liveDuration(): int
    {
        if (! $this->started_at) {
            return 0;
        }

        return (int) $this->started_at->diffInSeconds($this->ended_at ?? now());
    }
}
