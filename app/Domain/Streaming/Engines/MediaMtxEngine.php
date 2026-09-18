<?php

declare(strict_types=1);

namespace App\Domain\Streaming\Engines;

use App\Domain\Settings\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MediaMTX control API client (v3 API).
 * https://github.com/bluenviron/mediamtx
 */
class MediaMtxEngine implements StreamEngineInterface
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $internalRtmpUrl,
        private readonly string $publicRtmpUrl,
        private readonly string $hlsBaseUrl,
        private readonly ?string $apiUser = null,
        private readonly ?string $apiPassword = null,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('akstream.streaming');

        return new self(
            rtrim((string) $c['engine_api_url'], '/'),
            self::rtmpOrigin((string) $c['internal_rtmp_url']),
            rtrim((string) app(SettingsService::class)->get('streaming', 'rtmp_host', $c['public_rtmp_url']), '/'),
            rtrim((string) $c['hls_url'], '/'),
            env('STREAM_SERVER_API_USER'),
            env('STREAM_SERVER_API_PASSWORD'),
        );
    }

    /**
     * Host and port only, for the base every internal source URL is built on.
     *
     * Callers pass a complete MediaMTX path ("live/<key>", "branded/<key>"), while the
     * configured value is often copied from the OBS ingest URL and ends in "/live". Joining
     * the two produced "live/live/<key>" — a path nothing publishes to, so FFmpeg could not
     * open any source and every relay failed with "Input/output error".
     */
    public static function rtmpOrigin(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return preg_match('#^(rtmps?://[^/]+)#i', $url, $m) === 1 ? $m[1] : $url;
    }

    public function name(): string
    {
        return 'mediamtx';
    }

    private function http(): PendingRequest
    {
        $req = Http::timeout(5)->connectTimeout(3)->acceptJson();
        if ($this->apiUser) {
            $req = $req->withBasicAuth($this->apiUser, (string) $this->apiPassword);
        }

        return $req;
    }

    public function isReachable(): bool
    {
        try {
            return $this->http()->get($this->apiUrl.'/v3/paths/list', ['itemsPerPage' => 1])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function info(): array
    {
        try {
            $res = $this->http()->get($this->apiUrl.'/v3/config/global/get');
            if (! $res->successful()) {
                return ['reachable' => false, 'status' => $res->status()];
            }
            $cfg = $res->json();

            return [
                'reachable' => true,
                'rtmp' => $cfg['rtmp'] ?? null,
                'rtmpAddress' => $cfg['rtmpAddress'] ?? null,
                'hls' => $cfg['hls'] ?? null,
                'api' => $cfg['api'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['reachable' => false, 'error' => $e->getMessage()];
        }
    }

    public function listPaths(): array
    {
        $out = [];
        try {
            $page = 0;
            do {
                $res = $this->http()->get($this->apiUrl.'/v3/paths/list', ['page' => $page, 'itemsPerPage' => 100]);
                if (! $res->successful()) {
                    break;
                }
                $json = $res->json();
                foreach ($json['items'] ?? [] as $item) {
                    $out[$item['name']] = $this->normalizePath($item);
                }
                $page++;
            } while ($page < (int) ($json['pageCount'] ?? 1));
        } catch (\Throwable $e) {
            Log::warning('MediaMTX listPaths failed: '.$e->getMessage());
        }

        return $out;
    }

    public function getPath(string $path): ?array
    {
        try {
            $res = $this->http()->get($this->apiUrl.'/v3/paths/get/'.rawurlencode($path));
            if ($res->status() === 404) {
                return null;
            }
            if (! $res->successful()) {
                return null;
            }

            return $this->normalizePath($res->json());
        } catch (\Throwable) {
            return null;
        }
    }

    public function kickPublisher(string $path): bool
    {
        try {
            // Find the RTMP connection publishing this path and kick it
            $res = $this->http()->get($this->apiUrl.'/v3/rtmpconns/list', ['itemsPerPage' => 200]);
            foreach ($res->json('items') ?? [] as $conn) {
                if (($conn['path'] ?? null) === $path && ($conn['state'] ?? '') === 'publish') {
                    $this->http()->post($this->apiUrl.'/v3/rtmpconns/kick/'.rawurlencode($conn['id']));

                    return true;
                }
            }
            $res = $this->http()->get($this->apiUrl.'/v3/srtconns/list', ['itemsPerPage' => 200]);
            foreach ($res->json('items') ?? [] as $conn) {
                if (($conn['path'] ?? null) === $path && ($conn['state'] ?? '') === 'publish') {
                    $this->http()->post($this->apiUrl.'/v3/srtconns/kick/'.rawurlencode($conn['id']));

                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MediaMTX kick failed: '.$e->getMessage());
        }

        return false;
    }

    public function internalSourceUrl(string $path): string
    {
        return $this->internalRtmpUrl.'/'.$path;
    }

    public function publicIngestUrl(): string
    {
        return $this->publicRtmpUrl;
    }

    public function hlsUrl(string $path): string
    {
        return $this->hlsBaseUrl.'/'.$path.'/index.m3u8';
    }

    private function normalizePath(array $item): array
    {
        $tracks = $item['tracks'] ?? [];
        $source = $item['source'] ?? null;

        return [
            'name' => $item['name'] ?? '',
            'ready' => (bool) ($item['ready'] ?? false),
            'ready_time' => $item['readyTime'] ?? null,
            'bytes_received' => (int) ($item['bytesReceived'] ?? 0),
            'bytes_sent' => (int) ($item['bytesSent'] ?? 0),
            'readers' => is_array($item['readers'] ?? null) ? count($item['readers']) : 0,
            'tracks' => is_array($tracks) ? $tracks : [],
            'source_type' => is_array($source) ? ($source['type'] ?? null) : null,
        ];
    }
}
