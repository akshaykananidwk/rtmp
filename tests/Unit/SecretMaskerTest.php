<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SecretMasker;
use Tests\TestCase;

class SecretMaskerTest extends TestCase
{
    public function test_masks_stream_keys_in_rtmp_urls(): void
    {
        $this->assertSame('rtmp://a.rtmp.youtube.com/live2/***', SecretMasker::maskString('rtmp://a.rtmp.youtube.com/live2/abcd-efgh-ijkl'));
        $this->assertStringNotContainsString('AKDWK-ABCDEFGH-IJKLMNOP-QRSTUVWX', SecretMasker::maskString('publishing AKDWK-ABCDEFGH-IJKLMNOP-QRSTUVWX now'));
    }

    public function test_masks_github_tokens_and_bearer(): void
    {
        $this->assertStringNotContainsString('ghp_', SecretMasker::maskString('token ghp_abcdefghijklmnopqrstuvwxyz1234567890'));
        $this->assertSame('Authorization: Bearer ***', SecretMasker::maskString('Authorization: Bearer eyJhbGciOi.abc.def'));
    }

    public function test_masks_sensitive_array_keys_recursively(): void
    {
        $masked = SecretMasker::maskArray(['password' => 'x', 'nested' => ['github_token' => 'y', 'name' => 'ok'], 'stream_key' => 'z']);
        $this->assertSame('***', $masked['password']);
        $this->assertSame('***', $masked['nested']['github_token']);
        $this->assertSame('ok', $masked['nested']['name']);
        $this->assertSame('***', $masked['stream_key']);
    }
}
