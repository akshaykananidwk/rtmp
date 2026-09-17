<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class PlatformToken extends Model
{
    use HasUlids;

    protected $fillable = ['platform_account_id', 'token_type', 'access_token_encrypted', 'refresh_token_encrypted', 'scopes', 'expires_at'];

    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class, 'platform_account_id');
    }

    public function accessToken(): string
    {
        return Crypt::decryptString($this->access_token_encrypted);
    }

    public function refreshToken(): ?string
    {
        return $this->refresh_token_encrypted ? Crypt::decryptString($this->refresh_token_encrypted) : null;
    }

    public function setAccessToken(string $token): void
    {
        $this->access_token_encrypted = Crypt::encryptString($token);
    }

    public function setRefreshToken(?string $token): void
    {
        $this->refresh_token_encrypted = $token ? Crypt::encryptString($token) : null;
    }

    public function isExpired(int $skewSeconds = 120): bool
    {
        return $this->expires_at !== null && $this->expires_at->subSeconds($skewSeconds)->isPast();
    }
}
