<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use App\Support\SecretMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Database';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            DB::select('select 1');
            $ms = round((microtime(true) - $start) * 1000, 1);
            $details = ['driver' => DB::connection()->getDriverName(), 'latency_ms' => $ms];

            foreach (['users', 'stream_endpoints', 'updates', 'migrations'] as $t) {
                if (! Schema::hasTable($t)) {
                    return CheckResult::fail($this->name(), "Table '$t' is missing", $details);
                }
            }
            $pending = collect(DB::table('migrations')->pluck('migration'));
            $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));
            $missing = $files->diff($pending);
            $details['pending_migrations'] = $missing->values()->all();
            if ($missing->isNotEmpty()) {
                return CheckResult::fail($this->name(), $missing->count().' migration(s) pending', $details);
            }

            return CheckResult::pass($this->name(), 'Connected ('.$ms.' ms)', $details);
        } catch (\Throwable $e) {
            return CheckResult::fail($this->name(), 'Connection failed: '.SecretMasker::maskString($e->getMessage()));
        }
    }
}
