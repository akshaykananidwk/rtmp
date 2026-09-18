<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Support\SecretMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const GROUPS = ['general', 'streaming', 'registration', 'recording', 'storage', 'mail', 'security', 'platforms', 'notifications', 'backups'];

    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('settings.view');
        $values = [];
        foreach (self::GROUPS as $g) {
            $values[$g] = $this->settings->group($g);
        }
        // secrets: never send the value, only whether it is set
        foreach (SettingsService::ENCRYPTED_KEYS as $full) {
            [$g, $k] = explode('.', $full, 2);
            $values[$g][$k] = isset($values[$g][$k]) && $values[$g][$k] !== '' ? SecretMasker::hint((string) $values[$g][$k]) : '';
        }

        return view('admin.settings', ['values' => $values, 'tab' => $request->query('tab', 'general'), 'timezones' => \DateTimeZone::listIdentifiers(), 'canManage' => $request->user()->can('settings.manage')]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless(in_array($group, self::GROUPS, true), 404);

        $rules = $this->rules($group);
        $data = $request->validate($rules);

        // File uploads (logo / favicon) – validated MIME, randomised names, stored on public disk
        foreach (['logo', 'favicon'] as $fileKey) {
            if ($group === 'general' && $request->hasFile($fileKey)) {
                $file = $request->file($fileKey);
                if (! in_array($file->getMimeType(), config('akstream.security.allowed_upload_mimes'), true)) {
                    return back()->with('error', 'Invalid image type for '.$fileKey);
                }
                $ext = strtolower($file->getClientOriginalExtension());
                if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'ico', 'svg'], true)) {
                    return back()->with('error', 'Invalid file extension for '.$fileKey);
                }
                $path = $file->storeAs('branding', $fileKey.'-'.Str::random(12).'.'.$ext, 'public');
                $data[$fileKey] = $path;
            }
            unset($rules[$fileKey]);
        }

        $values = [];
        foreach ($rules as $key => $rule) {
            $key = explode('.', $key)[0];
            if (! array_key_exists($key, $data)) {
                if (str_ends_with($key, '_enabled') || in_array($key, ['auto_distribution', 'recording_enabled', 'two_factor_required', 'maintenance_mode', 'encrypt', 'auto_enabled'], true)) {
                    $values[$key] = '0';
                }

                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (in_array($group.'.'.$key, SettingsService::ENCRYPTED_KEYS, true) && ($value === null || $value === '')) {
                continue; // keep existing secret
            }
            $values[$key] = $value;
        }

        $this->settings->setMany($group, $values);
        $this->audit->log('settings.changed', null, ['group' => $group, 'keys' => array_keys($values)]);

        if ($group === 'security' && array_key_exists('maintenance_mode', $values)) {
            try {
                $values['maintenance_mode'] === '1' ? Artisan::call('down', ['--secret' => $request->user()->id, '--render' => 'errors::503']) : Artisan::call('up');
            } catch (\Throwable) {
            }
        }

        return redirect()->route('admin.settings', ['tab' => $group])->with('status', ucfirst($group).' settings saved.');
    }

    public function cron(): View
    {
        $this->authorize('settings.view');

        return view('admin.cron', ['phpBinary' => PHP_BINARY, 'basePath' => base_path()]);
    }

    private function rules(string $group): array
    {
        return match ($group) {
            'general' => [
                'app_name' => ['required', 'string', 'max:100'],
                'timezone' => ['required', 'timezone:all'],
                'contact_email' => ['nullable', 'email', 'max:190'],
                'support_phone' => ['nullable', 'string', 'max:32'],
                'address' => ['nullable', 'string', 'max:500'],
                'logo' => ['nullable', 'file', 'max:'.config('akstream.security.max_upload_kb')],
                'favicon' => ['nullable', 'file', 'max:1024'],
            ],
            'streaming' => [
                'rtmp_host' => ['required', 'string', 'max:255', 'regex:#^rtmps?://[A-Za-z0-9.\-]+(?::\d{1,5})?/[A-Za-z0-9_\-]+$#'],
                'default_bitrate' => ['required', 'integer', 'min:500', 'max:50000'],
                'default_resolution' => ['required', 'string', 'regex:/^\d{3,4}x\d{3,4}$/'],
                'retry_count' => ['required', 'integer', 'min:0', 'max:50'],
                'auto_distribution' => ['nullable', 'boolean'],
                'recording_enabled' => ['nullable', 'boolean'],
            ],
            'recording' => [
                'format' => ['required', 'in:mp4,mkv,flv'],
                'resolution' => ['required', 'string', 'max:20'],
                'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
                'max_size_mb' => ['required', 'integer', 'min:100', 'max:1000000'],
            ],
            'storage' => [
                'driver' => ['required', 'in:local,s3'],
                'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
                's3_key' => ['nullable', 'string', 'max:255'],
                's3_secret' => ['nullable', 'string', 'max:255'],
                's3_region' => ['nullable', 'string', 'max:64'],
                's3_bucket' => ['nullable', 'string', 'max:255'],
                's3_endpoint' => ['nullable', 'url', 'max:255'],
            ],
            'mail' => [
                'host' => ['nullable', 'string', 'max:255'],
                'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:255'],
                'encryption' => ['nullable', 'in:tls,ssl,none'],
                'from_address' => ['nullable', 'email', 'max:190'],
                'from_name' => ['nullable', 'string', 'max:100'],
            ],
            'registration' => [
                'open' => ['nullable', 'boolean'],
                'default_plan' => ['required', 'string', 'max:40'],
                'max_stream_keys' => ['required', 'integer', 'min:1', 'max:100'],
                'max_destinations' => ['required', 'integer', 'min:1', 'max:100'],
                'max_monthly_minutes' => ['required', 'integer', 'min:0', 'max:1000000'],
                'require_email_verification' => ['nullable', 'boolean'],
            ],
            'security' => [
                'session_timeout' => ['required', 'integer', 'min:5', 'max:1440'],
                'two_factor_required' => ['nullable', 'boolean'],
                'login_max_attempts' => ['required', 'integer', 'min:3', 'max:20'],
                'ip_allowlist' => ['nullable', 'string', 'max:2000', 'regex:/^[0-9a-fA-F.:\/,\s]*$/'],
                'maintenance_mode' => ['nullable', 'boolean'],
            ],
            'platforms' => [
                'youtube_client_id' => ['nullable', 'string', 'max:255'],
                'youtube_client_secret' => ['nullable', 'string', 'max:255'],
                'meta_app_id' => ['nullable', 'string', 'max:64'],
                'meta_app_secret' => ['nullable', 'string', 'max:255'],
                'meta_webhook_verify_token' => ['nullable', 'string', 'max:128'],
                'twitch_client_id' => ['nullable', 'string', 'max:255'],
                'twitch_client_secret' => ['nullable', 'string', 'max:255'],
            ],
            'notifications' => [
                'email_enabled' => ['nullable', 'boolean'],
                'whatsapp_enabled' => ['nullable', 'boolean'],
                'phone_number_id' => ['nullable', 'string', 'max:64'],
                'access_token' => ['nullable', 'string', 'max:512'],
                'admin_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+]+$/'],
            ],
            'backups' => [
                'auto_enabled' => ['nullable', 'boolean'],
                'frequency' => ['required', 'in:daily,weekly'],
                'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
                'encrypt' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }
}
