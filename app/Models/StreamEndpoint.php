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

class StreamEndpoint extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'user_id', 'name', 'slug', 'key_hash', 'key_encrypted', 'key_hint', 'is_enabled', 'auto_distribute', 'record_enabled', 'overlay_id', 'status', 'last_seen_at', 'revoked_at'];

    protected $hidden = ['key_hash', 'key_encrypted'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auto_distribute' => 'boolean',
            'record_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function overlay(): BelongsTo
    {
        return $this->belongsTo(Overlay::class);
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(StreamDestination::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(StreamSession::class);
    }

    /** Plaintext key. Only call when strictly required (copy button, engine config). Never log it. */
    public function plainKey(): string
    {
        return Crypt::decryptString($this->key_encrypted);
    }

    public function maskedKey(): string
    {
        return str_repeat('•', 12).$this->key_hint;
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && $this->revoked_at === null && $this->deleted_at === null;
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    /** MediaMTX path name. */
    public function pathName(): string
    {
        return $this->slug;
    }

    public function activeSession(): ?StreamSession
    {
        return $this->sessions()->whereIn('status', ['detected', 'live'])->latest('started_at')->first();
    }
}
