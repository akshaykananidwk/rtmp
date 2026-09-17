<?php

declare(strict_types=1);

namespace App\Domain\Installer;

use Illuminate\Support\Facades\File;

/** Safely writes keys into .env (creating it from .env.example) without touching unrelated lines. */
class EnvWriter
{
    public function __construct(private readonly string $path) {}

    public static function forApp(): self
    {
        return new self(base_path('.env'));
    }

    public function ensureExists(): void
    {
        if (! File::exists($this->path)) {
            $example = dirname($this->path).'/.env.example';
            File::exists($example) ? File::copy($example, $this->path) : File::put($this->path, '');
            @chmod($this->path, 0640);
        }
    }

    /** @param array<string, scalar|null> $values */
    public function write(array $values): void
    {
        $this->ensureExists();
        $content = (string) File::get($this->path);
        $lines = explode("\n", $content);

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException("Invalid env key: $key");
            }
            $formatted = $key.'='.$this->format($value);
            $found = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*#?\s*'.preg_quote($key, '/').'\s*=/', $line)) {
                    $lines[$i] = $formatted;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $lines[] = $formatted;
            }
        }

        $tmp = $this->path.'.tmp';
        File::put($tmp, implode("\n", $lines));
        @chmod($tmp, 0640);
        if (! rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write .env');
        }
    }

    private function format(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $value = (string) $value;
        if ($value === '' || preg_match('/[\s#"\'$\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
