<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settings\SettingsService;
use App\Domain\Updates\GitHubClient;
use Illuminate\Console\Command;

/**
 * Points the in-panel updater at the repository this copy was installed from.
 *
 * Without it the Updates page has no repository and a branch defaulting to "main", so
 * "Check for update" can never work on an install that came from anywhere else. The
 * update script calls this so the panel is configured the moment the files land.
 */
class UpdateSourceCommand extends Command
{
    protected $signature = 'updates:source {repository : owner/repo} {branch=main} {--force : Overwrite values already set}';

    protected $description = 'Set the GitHub repository and branch the in-panel updater follows.';

    public function handle(SettingsService $settings): int
    {
        $repository = trim((string) $this->argument('repository'));
        $branch = trim((string) $this->argument('branch'));

        if (! GitHubClient::isValidRepository($repository)) {
            $this->error('Repository must look like owner/repo.');

            return self::FAILURE;
        }

        if (! GitHubClient::isValidBranch($branch)) {
            $this->error('Invalid branch name.');

            return self::FAILURE;
        }

        // Never quietly replace what the operator chose in the panel.
        $current = trim((string) $settings->get('updates', 'repository', ''));
        if ($current !== '' && ! $this->option('force')) {
            $this->line('Update source already set to '.$current.'@'.$settings->get('updates', 'branch', 'main').' — leaving it alone.');

            return self::SUCCESS;
        }

        $settings->set('updates', 'repository', $repository);
        $settings->set('updates', 'branch', $branch);
        $this->info('Update source set to '.$repository.'@'.$branch);

        return self::SUCCESS;
    }
}
