<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Settings\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);

        $defaults = [
            'general' => [
                'app_name' => config('app.name'),
                'timezone' => config('app.timezone', 'Asia/Kolkata'),
                'contact_email' => '',
                'support_phone' => config('akstream.brand.phone'),
                'address' => config('akstream.brand.address'),
            ],
            'streaming' => [
                'rtmp_host' => config('akstream.streaming.public_rtmp_url'),
                'default_bitrate' => '4500',
                'default_resolution' => '1920x1080',
                'retry_count' => (string) config('akstream.streaming.max_retries'),
                'auto_distribution' => config('akstream.streaming.auto_distribution') ? '1' : '0',
                'recording_enabled' => '0',
            ],
            'recording' => [
                'format' => 'mp4',
                'resolution' => 'source',
                'retention_days' => (string) config('akstream.recording.retention_days'),
                'max_size_mb' => (string) config('akstream.recording.max_size_mb'),
            ],
            'storage' => [
                'driver' => 'local',
                'retention_days' => (string) config('akstream.recording.retention_days'),
            ],
            'security' => [
                'session_timeout' => '120',
                'two_factor_required' => '0',
                'login_max_attempts' => '5',
                'ip_allowlist' => '',
                'maintenance_mode' => '0',
            ],
            'backups' => [
                'auto_enabled' => '1',
                'frequency' => 'daily',
                'retention_days' => (string) config('akstream.backups.retention_days'),
                'encrypt' => '0',
            ],
            'updates' => [
                'repository' => (string) config('akstream.updates.repository'),
                'branch' => (string) config('akstream.updates.branch', 'main'),
            ],
        ];

        foreach ($defaults as $group => $values) {
            foreach ($values as $key => $value) {
                if (! $settings->has($group, $key)) {
                    $settings->set($group, $key, $value);
                }
            }
        }
    }
}
