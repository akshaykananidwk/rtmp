<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Streaming\Engines\MediaMtxEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaMtxSourceUrlTest extends TestCase
{
    public static function baseUrls(): array
    {
        return [
            'bare host' => ['rtmp://127.0.0.1:1935'],
            'trailing slash' => ['rtmp://127.0.0.1:1935/'],
            // What the installer used to write, and what an operator copies from OBS.
            'with the ingest app segment' => ['rtmp://127.0.0.1:1935/live'],
            'with a trailing slash too' => ['rtmp://127.0.0.1:1935/live/'],
        ];
    }

    #[DataProvider('baseUrls')]
    public function test_the_source_url_names_the_path_exactly_once(string $configured): void
    {
        config([
            'akstream.streaming.engine_api_url' => 'http://127.0.0.1:9997',
            'akstream.streaming.internal_rtmp_url' => $configured,
            'akstream.streaming.public_rtmp_url' => 'rtmp://rtmp.example.com/live',
            'akstream.streaming.hls_url' => 'http://127.0.0.1:8888',
        ]);

        $engine = MediaMtxEngine::fromConfig();
        $key = 'AKDWK-UVUYPZVP-4UBF33DX-FZWADOWA';

        // MediaMTX registers the publisher as "live/<key>"; reading "live/live/<key>" hits a
        // path with no source, which is what made every relay fail with Input/output error.
        $this->assertSame('rtmp://127.0.0.1:1935/live/'.$key, $engine->internalSourceUrl('live/'.$key));
        $this->assertSame('rtmp://127.0.0.1:1935/branded/'.$key, $engine->internalSourceUrl('branded/'.$key));
        $this->assertStringNotContainsString('live/live', $engine->internalSourceUrl('live/'.$key));
    }

    public function test_the_public_ingest_url_keeps_its_app_segment(): void
    {
        config([
            'akstream.streaming.engine_api_url' => 'http://127.0.0.1:9997',
            'akstream.streaming.internal_rtmp_url' => 'rtmp://127.0.0.1:1935/live',
            'akstream.streaming.public_rtmp_url' => 'rtmp://rtmp.example.com/live',
            'akstream.streaming.hls_url' => 'http://127.0.0.1:8888',
        ]);

        // OBS needs Server = rtmp://host/live with the key separate, so this one is untouched.
        $this->assertSame('rtmp://rtmp.example.com/live', MediaMtxEngine::fromConfig()->publicIngestUrl());
    }

    public function test_origin_strips_only_the_path(): void
    {
        $this->assertSame('rtmp://127.0.0.1:1935', MediaMtxEngine::rtmpOrigin('rtmp://127.0.0.1:1935/live'));
        $this->assertSame('rtmps://media.example.com:1936', MediaMtxEngine::rtmpOrigin('rtmps://media.example.com:1936/live/x'));
        $this->assertSame('rtmp://host', MediaMtxEngine::rtmpOrigin('rtmp://host'));
    }
}
