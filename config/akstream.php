<?php

/*
|--------------------------------------------------------------------------
| AK COMPUTER – ONE LIVE EVERYWHERE application configuration
|--------------------------------------------------------------------------
*/

return [
    'version_file' => base_path('VERSION'),

    'brand' => [
        'name' => 'AK COMPUTER',
        'product' => 'ONE LIVE EVERYWHERE',
        'tagline' => 'Stream Once. Reach Everywhere.',
        'owner' => 'Akshay Kanani',
        'phone' => '9978123146',
        'address' => "1st Floor, Shreeji Shopping Center,\nNear City Palace Hotel,\nDwarka, Gujarat – 361335",
        'gst' => '24JHVPD9382M1ZA',
    ],

    'streaming' => [
        'public_rtmp_url' => env('STREAM_SERVER_URL', 'rtmp://localhost/live'),
        'engine_api_url' => env('STREAM_SERVER_API_URL', 'http://127.0.0.1:9997'),
        'internal_rtmp_url' => env('STREAM_INTERNAL_RTMP_URL', 'rtmp://127.0.0.1:1935'),
        'hls_url' => env('STREAM_HLS_URL', 'http://127.0.0.1:8888'),
        'engine_secret' => env('STREAM_ENGINE_SECRET'),
        'engine' => env('STREAM_ENGINE', 'mediamtx'),
        'node_id' => env('STREAM_NODE_ID', 'media-1'),
        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
        'auto_distribution' => (bool) env('STREAM_AUTO_DISTRIBUTION', false),
        'max_retries' => (int) env('STREAM_MAX_RETRIES', 5),
        // Exponential backoff schedule in seconds (retry n uses index n-1, last value repeats)
        'backoff' => [5, 15, 30, 60, 120],
        'stats_interval' => 5,
        'key_prefix' => 'AKDWK',
    ],

    'overlay' => [
        // TrueType font for text overlays. Empty = use the font bundled in
        // resources/fonts (works even when open_basedir hides /usr/share/fonts).
        'font' => env('OVERLAY_FONT', ''),
        'max_image_kb' => 2048,
    ],

    'recording' => [
        'disk' => env('RECORDING_DISK', 'local'),
        'path' => env('RECORDING_PATH', 'recordings'),
        'retention_days' => (int) env('RECORDING_RETENTION_DAYS', 30),
        'max_size_mb' => (int) env('RECORDING_MAX_SIZE_MB', 4096),
        'format' => env('RECORDING_FORMAT', 'mp4'),
    ],

    'updates' => [
        'repository' => env('GITHUB_REPOSITORY'),
        'branch' => env('GITHUB_BRANCH', 'main'),
        'token' => env('GITHUB_TOKEN'),
        'releases_path' => env('UPDATE_RELEASES_PATH'),
        'strategy' => env('UPDATE_STRATEGY', 'auto'), // auto | symlink | inplace
        'github_api' => 'https://api.github.com',
        'protected_paths' => [
            '.env', '.env.*', 'config.php', 'uploads/', 'storage/', 'public/uploads/',
            'user_uploads/', 'storage/app/', 'backup/', 'public/storage', 'vendor/', 'node_modules/',
            'database/database.sqlite', 'installed.lock',
        ],
        'health_timeout' => 20,
    ],

    'backups' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'path' => 'backups',
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
        'keep_minimum' => 3,
        'exclude' => ['vendor', 'node_modules', 'storage/app/backups', 'storage/app/releases', 'storage/framework/cache', 'storage/logs', '.git'],
    ],

    'disk' => [
        'warning_percent' => 80,
        'critical_percent' => 90,
    ],

    'security' => [
        'session_timeout_minutes' => (int) env('SESSION_LIFETIME', 120),
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,
        'password_min_length' => 10,
        'allowed_upload_mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'],
        'max_upload_kb' => 4096,
    ],

    'platforms' => [
        'youtube' => [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
            'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
        ],
        'facebook' => [
            'app_id' => env('META_APP_ID'),
            'app_secret' => env('META_APP_SECRET'),
            'graph_version' => 'v21.0',
            'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
            'scopes' => ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'publish_video'],
        ],
        'twitch' => [
            'client_id' => env('TWITCH_CLIENT_ID'),
            'client_secret' => env('TWITCH_CLIENT_SECRET'),
        ],
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'admin_number' => env('WHATSAPP_ADMIN_NUMBER'),
    ],
];
