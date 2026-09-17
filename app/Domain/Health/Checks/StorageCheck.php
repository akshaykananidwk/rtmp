<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use App\Domain\Storage\DiskMonitor;

class StorageCheck implements HealthCheckInterface
{
    public function __construct(private readonly DiskMonitor $disk) {}

    public function name(): string
    {
        return 'storage';
    }

    public function label(): string
    {
        return 'Storage';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $paths = [storage_path('app'), storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')];
        $notWritable = array_values(array_filter($paths, fn ($p) => ! is_dir($p) || ! is_writable($p)));
        $usage = $this->disk->usage();
        $details = ['disk' => $usage, 'not_writable' => $notWritable];

        if ($notWritable) {
            return CheckResult::fail($this->name(), 'Not writable: '.implode(', ', array_map('basename', $notWritable)), $details);
        }
        if ($usage['level'] === 'critical') {
            return CheckResult::fail($this->name(), 'Disk usage critical: '.$usage['percent'].'%', $details);
        }
        if ($usage['level'] === 'warning') {
            return CheckResult::warn($this->name(), 'Disk usage high: '.$usage['percent'].'%', $details);
        }

        return CheckResult::pass($this->name(), 'Writable, disk '.$usage['percent'].'% used', $details);
    }
}
