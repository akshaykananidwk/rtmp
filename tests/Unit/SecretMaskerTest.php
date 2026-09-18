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

    public function test_masks_secrets_in_free_form_text(): void
    {
        // Database drivers put the password straight into the exception message
        $masked = SecretMasker::maskString('SQLSTATE[HY000] [1045] Access denied for user "ak"@"localhost" (password: secret123)');
        $this->assertStringNotContainsString('secret123', $masked);
        $this->assertStringContainsString('Access denied for user', $masked);

        foreach (['DB_PASSWORD=Sup3rSecret!', 'MAIL_PASSWORD => hunter2', 'X-CSRF-TOKEN: abc123', 'client_secret=GOCSPX-abcdef'] as $line) {
            $this->assertStringNotContainsString(explode(is_int(strpos($line, '=>')) ? '=>' : (str_contains($line, ': ') ? ': ' : '='), $line)[1], SecretMasker::maskString($line), $line);
        }

        $this->assertStringNotContainsString('aGVsbG93b3JsZGhlbGxvd29ybGRoZWxsb3dvcmxkMTIzNA==', SecretMasker::maskString('APP_KEY=base64:aGVsbG93b3JsZGhlbGxvd29ybGRoZWxsb3dvcmxkMTIzNA=='));

        // Ordinary text must survive untouched
        $this->assertSame('The attribute [password] either does not exist', SecretMasker::maskString('The attribute [password] either does not exist'));
        $this->assertSame('Reference ID: ERR-20260918-WTIF', SecretMasker::maskString('Reference ID: ERR-20260918-WTIF'));
    }

    public function test_works_without_a_booted_framework(): void
    {
        // The error page can render before config is available
        $this->assertSame('rtmp://host/app/***', SecretMasker::maskString('rtmp://host/app/somekey'));
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
