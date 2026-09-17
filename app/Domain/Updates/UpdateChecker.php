<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Support\Version;
use Illuminate\Support\Facades\Cache;

/** "Check for update": compares deployed version/commit with the GitHub branch head. */
class UpdateChecker
{
    public const CACHE_KEY = 'updates.last_check';

    public function __construct(private readonly GitHubClient $github, private readonly ProtectedPaths $protected) {}

    public function lastCheck(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function check(): array
    {
        $head = $this->github->branchHead();
        $currentCommit = Version::commit();
        $currentVersion = Version::current();
        $latestVersion = trim((string) ($this->github->fileContents('VERSION', $head['sha']) ?? $currentVersion));
        $release = null;
        try {
            $release = $this->github->latestRelease();
        } catch (\Throwable) {
        }

        $files = [];
        $summary = null;
        $sameCommit = $currentCommit && strtolower(substr($currentCommit, 0, 7)) === strtolower(substr((string) $head['sha'], 0, 7));
        if ($currentCommit && ! $sameCommit) {
            try {
                $cmp = $this->github->compare($currentCommit, $head['sha']);
                $files = $cmp['files'];
                $summary = $cmp['summary'];
            } catch (\Throwable $e) {
                $summary = ['error' => 'Could not compute file diff: '.$e->getMessage()];
            }
        }

        $counts = ['added' => 0, 'modified' => 0, 'deleted' => 0, 'renamed' => 0, 'protected_skipped' => 0];
        $changes = [];
        foreach ($files as $f) {
            $status = match ($f['status']) {
                'added' => 'added', 'removed' => 'deleted', 'renamed' => 'renamed', default => 'modified'
            };
            $counts[$status]++;
            $protected = $this->protected->isProtected($f['filename']);
            if ($protected) {
                $counts['protected_skipped']++;
            }
            $changes[] = ['file' => $f['filename'], 'status' => $status, 'changes' => $f['changes'], 'protected' => $protected];
        }

        $available = ! $sameCommit && (Version::compare($latestVersion, $currentVersion) > 0 || ($currentCommit === null) || count($files) > 0 || ($summary['ahead_by'] ?? 0) > 0);

        $result = [
            'checked_at' => now()->toIso8601String(),
            'repository' => $this->github->repositoryName(),
            'branch' => $this->github->branch(),
            'current_version' => $currentVersion,
            'current_commit' => $currentCommit,
            'latest_version' => $latestVersion,
            'commit' => $head,
            'update_available' => $available,
            'same_commit' => $sameCommit,
            'changed_files' => count($files),
            'counts' => $counts,
            'changes' => array_slice($changes, 0, 500),
            'summary' => $summary,
            'estimated_size' => array_sum(array_map(fn ($f) => $f['changes'] * 60, $files)), // rough bytes estimate from diff lines
            'release' => $release,
            'release_notes' => $release && $release['tag'] && Version::normalize($release['tag']) === Version::normalize($latestVersion) ? $release['body'] : null,
        ];

        Cache::put(self::CACHE_KEY, $result, 3600);

        return $result;
    }
}
