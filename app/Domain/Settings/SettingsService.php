<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global (tenant_id = null) key/value settings with optional encryption.
 * Cached; secrets are decrypted only on read.
 */
class SettingsService
{
    private const CACHE_KEY = 'system_settings.all';

    private ?array $loaded = null;

    public const ENCRYPTED_KEYS = [
        'updates.github_token', 'mail.password', 'storage.s3_secret', 'notifications.access_token',
        'platforms.youtube_client_secret', 'platforms.meta_app_secret', 'platforms.twitch_client_secret',
        'backups.encryption_key', 'streaming.engine_secret',
    ];

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        $full = $group.'.'.$key;

        if (! array_key_exists($full, $all)) {
            return $default;
        }

        $row = $all[$full];
        if ($row['encrypted']) {
            try {
                return $row['value'] === null ? $default : Crypt::decryptString($row['value']);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $row['value'] ?? $default;
    }

    public function bool(string $group, string $key, bool $default = false): bool
    {
        $v = $this->get($group, $key, $default);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $group, string $key, int $default = 0): int
    {
        return (int) $this->get($group, $key, $default);
    }

    public function set(string $group, string $key, mixed $value, ?bool $encrypt = null): void
    {
        $encrypt ??= in_array($group.'.'.$key, self::ENCRYPTED_KEYS, true);
        $stored = $value === null ? null : (string) (is_bool($value) ? ($value ? '1' : '0') : $value);

        if ($encrypt && $stored !== null) {
            $stored = Crypt::encryptString($stored);
        }

        SystemSetting::updateOrCreate(
            ['tenant_id' => null, 'group' => $group, 'key' => $key],
            ['value' => $stored, 'is_encrypted' => $encrypt]
        );

        $this->flush();
    }

    /** Set many at once inside a transaction. Values equal to the "keep" sentinel are ignored. */
    public function setMany(string $group, array $values): void
    {
        DB::transaction(function () use ($group, $values): void {
            foreach ($values as $key => $value) {
                if ($value === '__KEEP__') {
                    continue;
                }
                $this->set($group, (string) $key, $value);
            }
        });
    }

    public function has(string $group, string $key): bool
    {
        return array_key_exists($group.'.'.$key, $this->all());
    }

    public function forget(string $group, string $key): void
    {
        SystemSetting::whereNull('tenant_id')->where('group', $group)->where('key', $key)->delete();
        $this->flush();
    }

    public function group(string $group): array
    {
        $out = [];
        foreach ($this->all() as $full => $row) {
            if (str_starts_with($full, $group.'.')) {
                $out[substr($full, strlen($group) + 1)] = $this->get($group, substr($full, strlen($group) + 1));
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            if (! Schema::hasTable('system_settings')) {
                return $this->loaded = [];
            }

            return $this->loaded = Cache::remember(self::CACHE_KEY, 300, function () {
                $rows = [];
                foreach (SystemSetting::whereNull('tenant_id')->get() as $s) {
                    $rows[$s->group.'.'.$s->key] = ['value' => $s->value, 'encrypted' => (bool) $s->is_encrypted];
                }

                return $rows;
            });
        } catch (\Throwable) {
            return $this->loaded = [];
        }
    }
}
