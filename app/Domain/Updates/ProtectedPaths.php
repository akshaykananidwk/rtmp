<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Models\UpdateProtectedPath;
use Illuminate\Support\Facades\Schema;

/**
 * Paths the updater must never overwrite or delete. Configurable in admin.
 * Patterns: exact file, "dir/" prefix, or shell glob (e.g. ".env.*").
 */
class ProtectedPaths
{
    private ?array $patterns = null;

    /** @return string[] */
    public function all(): array
    {
        if ($this->patterns !== null) {
            return $this->patterns;
        }
        $patterns = (array) config('akstream.updates.protected_paths', []);
        try {
            if (Schema::hasTable('update_protected_paths')) {
                $patterns = array_merge($patterns, UpdateProtectedPath::pluck('path')->all());
            }
        } catch (\Throwable) {
        }

        return $this->patterns = array_values(array_unique(array_map(fn ($p) => self::normalize($p), $patterns)));
    }

    public function refresh(): void
    {
        $this->patterns = null;
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        $path = preg_replace('#^(\./)+#', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    public static function isSafePattern(string $path): bool
    {
        $raw = str_replace('\\', '/', trim($path));
        if (str_starts_with($raw, '/') || str_contains($raw, '..')) {
            return false;
        }
        $n = self::normalize($path);

        return $n !== '' && ! str_contains($n, '..') && ! str_starts_with($n, '/') && strlen($n) <= 500;
    }

    public function isProtected(string $relativePath): bool
    {
        $rel = self::normalize($relativePath);
        if ($rel === '' || str_contains($rel, '..')) {
            return true; // anything suspicious is treated as protected (never written)
        }
        if ($rel === '.env.example') {
            return false; // template shipped with the code, never contains secrets
        }

        foreach ($this->all() as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $dir = rtrim($pattern, '/');
            if ($rel === $dir || str_starts_with($rel, $dir.'/')) {
                return true;
            }
            if (fnmatch($pattern, $rel, FNM_PATHNAME) || fnmatch($pattern, basename($rel))) {
                return true;
            }
        }

        return false;
    }
}
