<?php

declare(strict_types=1);

namespace App\Domain\Streaming;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Models\StreamEndpoint;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StreamKeyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function generateKey(): string
    {
        $prefix = (string) config('akstream.streaming.key_prefix', 'AKDWK');
        $random = strtoupper(Str::random(24));

        return $prefix.'-'.substr($random, 0, 8).'-'.substr($random, 8, 8).'-'.substr($random, 16, 8);
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public function create(Tenant $tenant, ?User $user, string $name, ?string $slug = null, array $attributes = []): StreamEndpoint
    {
        $key = $this->generateKey();
        $slug = $this->uniqueSlug($tenant, $slug ?: $name);

        $endpoint = DB::transaction(function () use ($tenant, $user, $name, $slug, $key, $attributes) {
            $endpoint = StreamEndpoint::create(array_merge([
                'tenant_id' => $tenant->id,
                'user_id' => $user?->id,
                'name' => $name,
                'slug' => $slug,
                'key_hash' => self::hash($key),
                'key_encrypted' => Crypt::encryptString($key),
                'key_hint' => substr($key, -4),
                'is_enabled' => true,
            ], $attributes));

            $this->audit->log('stream_key.created', $endpoint, ['name' => $name, 'slug' => $slug]);

            return $endpoint;
        });

        return $endpoint;
    }

    public function regenerate(StreamEndpoint $endpoint): string
    {
        $key = $this->generateKey();

        DB::transaction(function () use ($endpoint, $key): void {
            $endpoint->forceFill([
                'key_hash' => self::hash($key),
                'key_encrypted' => Crypt::encryptString($key),
                'key_hint' => substr($key, -4),
                'revoked_at' => null,
            ])->save();
            $this->audit->log('stream_key.regenerated', $endpoint);
        });

        return $key;
    }

    public function revoke(StreamEndpoint $endpoint): void
    {
        $endpoint->forceFill(['revoked_at' => now(), 'is_enabled' => false])->save();
        $this->audit->log('stream_key.revoked', $endpoint);
    }

    public function setEnabled(StreamEndpoint $endpoint, bool $enabled): void
    {
        $endpoint->forceFill(['is_enabled' => $enabled])->save();
        $this->audit->log($enabled ? 'stream_key.enabled' : 'stream_key.disabled', $endpoint);
    }

    /**
     * Resolve an endpoint from a plaintext key presented by the encoder (via the engine's auth hook).
     * Lookup is by hash so the plaintext never needs to be decrypted for auth.
     */
    public function findByKey(string $key): ?StreamEndpoint
    {
        return StreamEndpoint::withoutGlobalScopes()->where('key_hash', self::hash($key))->whereNull('deleted_at')->first();
    }

    public function findByPath(string $path): ?StreamEndpoint
    {
        return StreamEndpoint::withoutGlobalScopes()->where('slug', $path)->whereNull('deleted_at')->first();
    }

    public function uniqueSlug(Tenant $tenant, string $base): string
    {
        $slug = Str::of($base)->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->limit(40, '')->toString() ?: 'STREAM';
        $candidate = $slug;
        $i = 1;
        while (StreamEndpoint::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.str_pad((string) ++$i, 3, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    public function publicRtmpUrl(): string
    {
        return rtrim((string) app(SettingsService::class)->get('streaming', 'rtmp_host', config('akstream.streaming.public_rtmp_url')), '/');
    }
}
