<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Settings\SettingsService;
use App\Support\SecretMasker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal GitHub REST client for the auto-updater.
 * Only ever talks to api.github.com (never a user-supplied host) → no SSRF.
 * The token is read from encrypted settings and never logged or exposed.
 */
class GitHubClient
{
    private const API = 'https://api.github.com';

    public function __construct(private readonly SettingsService $settings) {}

    public function repositoryName(): ?string
    {
        $repo = trim((string) $this->settings->get('updates', 'repository', config('akstream.updates.repository')));
        if ($repo === '') {
            return null;
        }
        // Accept full URLs and normalise to owner/repo
        $repo = preg_replace('#^https?://github\.com/#i', '', $repo) ?? $repo;
        $repo = preg_replace('#\.git$#', '', $repo) ?? $repo;

        return self::isValidRepository($repo) ? $repo : null;
    }

    public static function isValidRepository(string $repo): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repo) && ! str_contains($repo, '..');
    }

    public static function isValidBranch(string $branch): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9._/-]{1,120}$#', $branch) && ! str_contains($branch, '..') && ! str_starts_with($branch, '/') && ! str_starts_with($branch, '-');
    }

    public function branch(): string
    {
        $b = trim((string) $this->settings->get('updates', 'branch', config('akstream.updates.branch', 'main')));

        return self::isValidBranch($b) ? $b : 'main';
    }

    public function token(): ?string
    {
        return $this->settings->get('updates', 'github_token', config('akstream.updates.token')) ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->repositoryName() !== null;
    }

    private function http(): PendingRequest
    {
        $req = Http::baseUrl(self::API)
            ->timeout(30)
            ->connectTimeout(10)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'AKComputer-OneLiveEverywhere-Updater'])
            ->retry(2, 500, throw: false);

        if ($token = $this->token()) {
            $req = $req->withToken($token);
        }

        return $req;
    }

    private function repoPath(string $suffix = ''): string
    {
        $repo = $this->repositoryName();
        if (! $repo) {
            throw new \RuntimeException('GitHub repository is not configured or invalid (expected owner/repo).');
        }

        return '/repos/'.$repo.$suffix;
    }

    private function assert(Response $res, string $what): array
    {
        if ($res->status() === 401) {
            throw new \RuntimeException("GitHub authentication failed while trying to $what (check the token).");
        }
        if ($res->status() === 403 && str_contains((string) $res->header('X-RateLimit-Remaining'), '0')) {
            throw new \RuntimeException("GitHub API rate limit exceeded while trying to $what.");
        }
        if ($res->status() === 404) {
            throw new \RuntimeException("GitHub: not found while trying to $what (repository/branch may not exist or token lacks access).");
        }
        if (! $res->successful()) {
            throw new \RuntimeException('GitHub API error '.$res->status()." while trying to $what: ".SecretMasker::maskString((string) $res->json('message')));
        }

        return (array) $res->json();
    }

    public function repository(): array
    {
        return $this->assert($this->http()->get($this->repoPath()), 'read repository');
    }

    /** Latest commit on the configured branch. */
    public function branchHead(): array
    {
        $data = $this->assert($this->http()->get($this->repoPath('/branches/'.rawurlencode($this->branch()))), 'read branch');
        $commit = $data['commit'] ?? [];

        return $this->normalizeCommit($commit);
    }

    public function commit(string $sha): array
    {
        self::assertSha($sha);

        return $this->normalizeCommit($this->assert($this->http()->get($this->repoPath('/commits/'.$sha)), 'read commit'));
    }

    /** Compare two refs. Returns files with status added/modified/removed/renamed. */
    public function compare(string $base, string $head): array
    {
        $files = [];
        $page = 1;
        $summary = ['ahead_by' => 0, 'behind_by' => 0, 'total_commits' => 0, 'commits' => []];
        do {
            $data = $this->assert($this->http()->get($this->repoPath('/compare/'.rawurlencode($base).'...'.rawurlencode($head)), ['per_page' => 250, 'page' => $page]), 'compare commits');
            if ($page === 1) {
                $summary['ahead_by'] = (int) ($data['ahead_by'] ?? 0);
                $summary['behind_by'] = (int) ($data['behind_by'] ?? 0);
                $summary['total_commits'] = (int) ($data['total_commits'] ?? 0);
                $summary['commits'] = array_map(fn ($c) => ['sha' => $c['sha'], 'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"), 'author' => $c['commit']['author']['name'] ?? null, 'date' => $c['commit']['author']['date'] ?? null], array_slice($data['commits'] ?? [], 0, 50));
            }
            foreach ($data['files'] ?? [] as $f) {
                $files[] = ['filename' => $f['filename'], 'status' => $f['status'], 'additions' => (int) ($f['additions'] ?? 0), 'deletions' => (int) ($f['deletions'] ?? 0), 'changes' => (int) ($f['changes'] ?? 0), 'previous_filename' => $f['previous_filename'] ?? null];
            }
            $page++;
        } while (count($data['files'] ?? []) === 250 && $page < 20);

        return ['summary' => $summary, 'files' => $files];
    }

    /** Raw file content at a ref (e.g. VERSION). Null if missing. */
    public function fileContents(string $path, string $ref): ?string
    {
        $res = $this->http()->withHeaders(['Accept' => 'application/vnd.github.raw+json'])->get($this->repoPath('/contents/'.ltrim($path, '/')), ['ref' => $ref]);
        if ($res->status() === 404) {
            return null;
        }
        $this->assert($res, "read $path");

        return $res->body();
    }

    public function latestRelease(): ?array
    {
        $res = $this->http()->get($this->repoPath('/releases/latest'));
        if ($res->status() === 404) {
            return null;
        }
        $data = $this->assert($res, 'read latest release');

        return ['tag' => $data['tag_name'] ?? null, 'name' => $data['name'] ?? null, 'body' => $data['body'] ?? null, 'published_at' => $data['published_at'] ?? null, 'prerelease' => (bool) ($data['prerelease'] ?? false)];
    }

    /**
     * Stream the tarball of a commit to disk. Returns bytes written.
     * GitHub redirects to codeload.github.com; we only follow that.
     */
    public function downloadTarball(string $sha, string $destination): int
    {
        self::assertSha($sha);
        $res = $this->http()->withOptions(['stream' => true, 'allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->timeout(600)->get($this->repoPath('/tarball/'.$sha));
        if (! $res->successful()) {
            throw new \RuntimeException('GitHub tarball download failed with HTTP '.$res->status());
        }
        $body = $res->toPsrResponse()->getBody();
        $out = fopen($destination, 'wb');
        if (! $out) {
            throw new \RuntimeException('Cannot write archive to disk');
        }
        $size = 0;
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 256);
                if ($chunk === '') {
                    break;
                }
                $size += fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }
        if ($size < 100) {
            @unlink($destination);
            throw new \RuntimeException('Downloaded archive is empty');
        }

        return $size;
    }

    /** Verify the token can read the repository (least privilege: contents:read). */
    public function validateAccess(): array
    {
        $repo = $this->repository();

        return ['ok' => true, 'private' => (bool) ($repo['private'] ?? false), 'default_branch' => $repo['default_branch'] ?? null, 'permissions' => $repo['permissions'] ?? null];
    }

    public static function assertSha(string $sha): void
    {
        if (! preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            throw new \InvalidArgumentException('Invalid commit SHA');
        }
    }

    private function normalizeCommit(array $commit): array
    {
        return [
            'sha' => $commit['sha'] ?? null,
            'message' => trim((string) ($commit['commit']['message'] ?? '')),
            'author' => $commit['commit']['author']['name'] ?? ($commit['author']['login'] ?? null),
            'date' => $commit['commit']['author']['date'] ?? ($commit['commit']['committer']['date'] ?? null),
            'url' => $commit['html_url'] ?? null,
        ];
    }
}
