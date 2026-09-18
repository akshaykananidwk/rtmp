<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes secrets from strings / arrays before they reach logs, exceptions or the UI.
 */
final class SecretMasker
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'token', 'access_token', 'refresh_token',
        'api_key', 'secret', 'client_secret', 'app_secret', 'stream_key', 'key', 'github_token', 'authorization',
        'cookie', 'db_password', 'mail_password', 'smtp_password', 'private_key', 'two_factor_secret',
    ];

    public static function maskArray(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::maskArray($v);

                continue;
            }
            if (is_string($k) && self::isSensitiveKey($k)) {
                $data[$k] = '***';
            }
        }

        return $data;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $s) {
            if ($key === $s || str_ends_with($key, '_'.$s) || str_contains($key, $s.'_') || str_contains($key, 'token') || str_contains($key, 'secret') || str_contains($key, 'password')) {
                return true;
            }
        }

        return false;
    }

    /** Masks stream keys / tokens that appear inside RTMP URLs or free-form strings. */
    public static function maskString(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        // rtmp://host/app/STREAMKEY  -> rtmp://host/app/***
        $text = preg_replace('#(rtmps?://[^\s/]+/[^\s/]+/)([^\s/?"\']+)#i', '$1***', $text) ?? $text;
        // query tokens
        $text = preg_replace('#([?&](?:token|key|access_token|code|secret|password)=)[^&\s"\']+#i', '$1***', $text) ?? $text;
        // GitHub tokens
        $text = preg_replace('#\b(gh[pousr]_[A-Za-z0-9]{10,}|github_pat_[A-Za-z0-9_]{10,})\b#', '***', $text) ?? $text;
        // Bearer headers
        $text = preg_replace('#(Bearer\s+)[A-Za-z0-9\-._~+/]+=*#i', '$1***', $text) ?? $text;
        // "password: value", "secret=value", "api_key => value" in free-form text
        // (e.g. PDO messages such as: Access denied for user "x"@"host" (password: hunter2))
        $text = preg_replace('#\b([A-Za-z0-9_\-]*(?:password|passwd|pwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|token))\s*(?:=>|[:=])\s*[^\s,;)\]"\']+#i', '$1: ***', $text) ?? $text;
        // base64 application keys
        $text = preg_replace('#\bbase64:[A-Za-z0-9+/]{30,}={0,2}#', '***', $text) ?? $text;
        // Stream keys with our prefix (must work even when the framework is not booted)
        $prefix = 'AKDWK';
        try {
            if (function_exists('app') && app()->bound('config')) {
                $prefix = (string) config('akstream.streaming.key_prefix', 'AKDWK');
            }
        } catch (\Throwable) {
            // keep the default prefix
        }
        $text = preg_replace('#\b('.preg_quote($prefix, '#').'-[A-Za-z0-9\-]{8,})\b#', '***', $text) ?? $text;

        return $text;
    }

    public static function hint(?string $secret, int $visible = 4): string
    {
        if (! $secret) {
            return '—';
        }

        return str_repeat('•', 10).substr($secret, -$visible);
    }
}
