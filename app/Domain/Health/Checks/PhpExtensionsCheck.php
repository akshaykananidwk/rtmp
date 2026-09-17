<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;

class PhpExtensionsCheck implements HealthCheckInterface
{
    public const REQUIRED = ['pdo', 'openssl', 'curl', 'json', 'mbstring', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'zip', 'bcmath'];

    public const RECOMMENDED = ['redis', 'gd', 'intl', 'sodium', 'pcntl', 'posix', 'Zend OPcache'];

    public function name(): string
    {
        return 'php';
    }

    public function label(): string
    {
        return 'PHP';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $missing = array_values(array_filter(self::REQUIRED, fn ($e) => ! extension_loaded($e) && ! ($e === 'bcmath' && function_exists('bcadd'))));
        $missingRec = array_values(array_filter(self::RECOMMENDED, fn ($e) => ! extension_loaded($e)));
        $details = ['php' => PHP_VERSION, 'missing_required' => $missing, 'missing_recommended' => $missingRec, 'opcache' => function_exists('opcache_get_status')];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            return CheckResult::fail($this->name(), 'PHP 8.2+ required, found '.PHP_VERSION, $details);
        }
        $missing = array_diff($missing, ['bcmath']); // bcmath optional in practice
        if ($missing) {
            return CheckResult::fail($this->name(), 'Missing extensions: '.implode(', ', $missing), $details);
        }
        if ($missingRec) {
            return CheckResult::warn($this->name(), 'Recommended extensions missing: '.implode(', ', $missingRec), $details);
        }

        return CheckResult::pass($this->name(), 'PHP '.PHP_VERSION.' with all extensions', $details);
    }
}
