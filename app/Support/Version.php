<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

final class Version
{
    public static function current(): string
    {
        $file = config('akstream.version_file', base_path('VERSION'));

        if (File::exists($file)) {
            $v = trim((string) File::get($file));
            if ($v !== '') {
                return $v;
            }
        }

        return '0.0.0';
    }

    /** Git commit SHA of the deployed code, if recorded. */
    public static function commit(): ?string
    {
        $file = (string) (config('akstream.commit_file') ?: base_path('COMMIT'));
        if (File::exists($file)) {
            $sha = trim((string) File::get($file));

            return $sha !== '' ? $sha : null;
        }

        $head = base_path('.git/HEAD');
        if (File::exists($head)) {
            $ref = trim((string) File::get($head));
            if (str_starts_with($ref, 'ref: ')) {
                $refFile = base_path('.git/'.substr($ref, 5));

                return File::exists($refFile) ? trim((string) File::get($refFile)) : null;
            }

            return $ref;
        }

        return null;
    }

    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    public static function normalize(string $v): string
    {
        $v = ltrim(trim($v), 'vV');

        return preg_replace('/[^0-9A-Za-z.\-+]/', '', $v) ?: '0.0.0';
    }
}
