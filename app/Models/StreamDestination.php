<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class StreamDestination extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = ['idle', 'connecting', 'connected', 'live', 'reconnecting', 'failed', 'disabled'];

    protected $fillable = ['tenant_id', 'stream_endpoint_id', 'platform_account_id', 'platform', 'name', 'account_name', 'connection_method', 'rtmp_url', 'stream_key_encrypted', 'stream_key_hint', 'options', 'is_enabled', 'status', 'last_error', 'last_error_at', 'last_tested_at', 'last_test_result', 'last_success_at', 'sort_order'];

    protected $hidden = ['stream_key_encrypted'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_enabled' => 'boolean',
            'last_error_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(StreamEndpoint::class, 'stream_endpoint_id');
    }

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }

    public function sessionDestinations(): HasMany
    {
        return $this->hasMany(StreamSessionDestination::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StreamDestinationLog::class);
    }

    public function setStreamKey(?string $key): void
    {
        $this->stream_key_encrypted = $key === null || $key === '' ? null : Crypt::encryptString($key);
        $this->stream_key_hint = $key ? substr($key, -4) : null;
    }

    public function streamKey(): ?string
    {
        return $this->stream_key_encrypted ? Crypt::decryptString($this->stream_key_encrypted) : null;
    }

    public function maskedKey(): string
    {
        return $this->stream_key_hint ? str_repeat('•', 10).$this->stream_key_hint : '—';
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }

    public function displayStatus(): string
    {
        return $this->is_enabled ? ($this->status ?? 'idle') : 'disabled';
    }
}
