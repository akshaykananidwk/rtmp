<?php

declare(strict_types=1);

namespace App\Domain\Health;

interface HealthCheckInterface
{
    public function name(): string;

    public function label(): string;

    /** Critical checks failing => update rollback. Non-critical => warning only. */
    public function critical(): bool;

    public function run(): CheckResult;
}
