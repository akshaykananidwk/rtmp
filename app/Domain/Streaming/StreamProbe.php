<?php

declare(strict_types=1);

namespace App\Domain\Streaming;

use Symfony\Component\Process\Process;

/** Runs ffprobe against the internal RTMP URL to learn resolution/fps/codecs. */
class StreamProbe
{
    public function probe(string $url): ?array
    {
        $bin = (string) config('akstream.streaming.ffprobe', 'ffprobe');

        $process = new Process([
            $bin, '-v', 'error', '-rw_timeout', '5000000', '-analyzeduration', '3000000', '-probesize', '3000000',
            '-show_streams', '-show_format', '-of', 'json', $url,
        ]);
        $process->setTimeout(20);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $json = json_decode($process->getOutput(), true);
        if (! is_array($json)) {
            return null;
        }

        $out = ['resolution' => null, 'fps' => null, 'video_codec' => null, 'audio_codec' => null, 'bitrate_kbps' => null];
        foreach ($json['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'video' && ! $out['video_codec']) {
                $out['video_codec'] = strtoupper((string) ($s['codec_name'] ?? ''));
                if (! empty($s['width']) && ! empty($s['height'])) {
                    $out['resolution'] = $s['width'].'x'.$s['height'];
                }
                $rate = (string) ($s['avg_frame_rate'] ?? $s['r_frame_rate'] ?? '0/1');
                if (str_contains($rate, '/')) {
                    [$n, $d] = explode('/', $rate, 2);
                    $out['fps'] = (float) $d > 0 ? round((float) $n / (float) $d, 2) : null;
                }
            }
            if (($s['codec_type'] ?? '') === 'audio' && ! $out['audio_codec']) {
                $out['audio_codec'] = strtoupper((string) ($s['codec_name'] ?? ''));
            }
        }
        if (! empty($json['format']['bit_rate'])) {
            $out['bitrate_kbps'] = (int) round(((int) $json['format']['bit_rate']) / 1000);
        }

        return $out;
    }
}
