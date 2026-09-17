<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use App\Support\Version;
use Illuminate\Support\Facades\Http;

class ApplicationCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'application';
    }

    public function label(): string
    {
        return 'Application';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $details = ['version' => Version::current(), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'env' => app()->environment(), 'debug' => config('app.debug')];

        if (! config('app.key')) {
            return CheckResult::fail($this->name(), 'APP_KEY is not set', $details);
        }
        if (app()->isProduction() && config('app.debug')) {
            return CheckResult::warn($this->name(), 'APP_DEBUG is enabled in production', $details);
        }

        // Self HTTP check (only when running from CLI/scheduler; avoid recursion inside a web request)
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            try {
                $res = Http::timeout((int) config('akstream.updates.health_timeout', 20))->withoutVerifying()->get(rtrim((string) config('app.url'), '/').'/up');
                $details['http_status'] = $res->status();
                if (! $res->successful()) {
                    return CheckResult::fail($this->name(), 'HTTP self-check returned '.$res->status(), $details);
                }
            } catch (\Throwable $e) {
                $details['http_error'] = $e->getMessage();

                return CheckResult::warn($this->name(), 'HTTP self-check could not reach APP_URL (may be normal behind a firewall)', $details);
            }
        }

        return CheckResult::pass($this->name(), 'Version '.$details['version'], $details);
    }
}
