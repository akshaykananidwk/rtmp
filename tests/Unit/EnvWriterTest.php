<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Installer\EnvWriter;
use Tests\TestCase;

class EnvWriterTest extends TestCase
{
    public function test_writes_and_replaces_keys_without_touching_others(): void
    {
        $file = sys_get_temp_dir().'/env-'.uniqid();
        file_put_contents($file, "APP_NAME=Old\n# comment\nDB_HOST=127.0.0.1\n");
        $w = new EnvWriter($file);
        $w->write(['APP_NAME' => 'AK Computer – Live', 'NEW_KEY' => 'p@ss word', 'APP_DEBUG' => false]);
        $c = file_get_contents($file);
        $this->assertStringContainsString('APP_NAME="AK Computer – Live"', $c);
        $this->assertStringContainsString("# comment\nDB_HOST=127.0.0.1", $c);
        $this->assertStringContainsString('NEW_KEY="p@ss word"', $c);
        $this->assertStringContainsString('APP_DEBUG=false', $c);
        $this->assertSame(1, substr_count($c, 'APP_NAME='));
        unlink($file);
    }

    public function test_rejects_invalid_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvWriter(sys_get_temp_dir().'/env-'.uniqid()))->write(['bad key; rm' => 'x']);
    }
}
