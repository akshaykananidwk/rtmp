<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use App\Domain\Updates\GitHubClient;
use App\Support\SecretMasker;

class GitHubCheck implements HealthCheckInterface
{
    public function __construct(private readonly GitHubClient $github) {}

    public function name(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        if (! $this->github->isConfigured()) {
            return CheckResult::warn($this->name(), 'Auto-update repository not configured', []);
        }
        try {
            $repo = $this->github->repository();
            $details = ['repository' => $this->github->repositoryName(), 'branch' => $this->github->branch(), 'private' => $repo['private'] ?? null, 'default_branch' => $repo['default_branch'] ?? null];

            return CheckResult::pass($this->name(), 'Repository reachable', $details);
        } catch (\Throwable $e) {
            return CheckResult::fail($this->name(), SecretMasker::maskString($e->getMessage()));
        }
    }
}
