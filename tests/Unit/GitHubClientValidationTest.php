<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Updates\GitHubClient;
use Tests\TestCase;

class GitHubClientValidationTest extends TestCase
{
    public function test_repository_validation_blocks_ssrf_and_traversal(): void
    {
        $this->assertTrue(GitHubClient::isValidRepository('akshay/rtmp'));
        $this->assertTrue(GitHubClient::isValidRepository('ak-computer/one.live_everywhere'));
        foreach (['http://evil.com/x', 'a/b/c', '../x', 'a/..', 'owner/repo?x=1', 'owner/repo#frag', '', 'onlyowner', 'own er/repo'] as $bad) {
            $this->assertFalse(GitHubClient::isValidRepository($bad), "$bad should be invalid");
        }
    }

    public function test_branch_and_sha_validation(): void
    {
        $this->assertTrue(GitHubClient::isValidBranch('main'));
        $this->assertTrue(GitHubClient::isValidBranch('release/1.2'));
        $this->assertFalse(GitHubClient::isValidBranch('../x'));
        $this->assertFalse(GitHubClient::isValidBranch('-flag'));
        $this->expectException(\InvalidArgumentException::class);
        GitHubClient::assertSha('not-a-sha; rm -rf /');
    }
}
