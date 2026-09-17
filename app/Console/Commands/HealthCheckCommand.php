<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Health\HealthService;
use Illuminate\Console\Command;

class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--json : Output JSON} {--trigger=manual} {--alert : Send alerts for failures}';

    protected $description = 'Run all health checks and print a report';

    public function handle(HealthService $health): int
    {
        $run = $health->run((string) $this->option('trigger'), true, (bool) $this->option('alert'));
        $report = $health->report($run);

        if ($this->option('json')) {
            $this->line(json_encode(['run_id' => $run['run_id'], 'ok' => $run['ok'], 'critical_ok' => $run['critical_ok'], 'summary' => $run['summary'], 'report' => $report, 'results' => array_map(fn ($r) => $r->toArray(), $run['results'])]));
        } else {
            $this->line($report);
        }

        return $run['critical_ok'] ? self::SUCCESS : self::FAILURE;
    }
}
