<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Locates external binaries without ever throwing.
 *
 * Shared hosting panels (aaPanel/cPanel) set open_basedir, which makes is_executable()
 * on /usr/bin/* raise a warning-turned-exception. Every lookup therefore goes through here.
 */
final class BinaryLocator
{
    public static function find(string $binary): ?string
    {
        try {
            if (@is_executable($binary)) {
                return $binary;
            }
        } catch (\Throwable) {
            // open_basedir restriction – keep looking
        }

        try {
            if ($found = (new ExecutableFinder)->find($binary)) {
                return $found;
            }
        } catch (\Throwable) {
            // ignore
        }

        $name = basename($binary);
        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/', '/bin/', '/usr/sbin/'] as $dir) {
            try {
                if (@is_executable($dir.$name)) {
                    return $dir.$name;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** True when open_basedir would hide the binary's directory from PHP. */
    public static function blockedByOpenBasedir(string $binary): bool
    {
        $setting = (string) ini_get('open_basedir');
        if ($setting === '') {
            return false;
        }

        $dir = str_contains($binary, '/') ? dirname($binary) : '/usr/bin';
        foreach (explode(PATH_SEPARATOR, $setting) as $allowed) {
            if ($allowed !== '' && str_starts_with($dir.'/', rtrim($allowed, '/').'/')) {
                return false;
            }
        }

        return true;
    }
}
