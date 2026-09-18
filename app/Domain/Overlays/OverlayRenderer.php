<?php

declare(strict_types=1);

namespace App\Domain\Overlays;

use App\Models\Overlay;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an Overlay record into an FFmpeg filter chain.
 *
 * Design notes
 *  - Static text goes through drawtext's `textfile` + `reload=1`, so editing a line in the
 *    admin panel changes the live output within a second WITHOUT restarting the encoder,
 *    and no FFmpeg escaping of user input is ever required.
 *  - The clock uses FFmpeg's own `%{localtime}` expansion, so it ticks on its own.
 *  - Everything is rendered once into a single branded stream that all destinations copy,
 *    so N platforms cost one encode, not N.
 */
class OverlayRenderer
{
    private int $frameWidth = 1920;

    private int $frameHeight = 1080;

    public const POSITIONS = [
        'top_left' => 'Top left', 'top_center' => 'Top center', 'top_right' => 'Top right',
        'middle_left' => 'Middle left', 'center' => 'Center', 'middle_right' => 'Middle right',
        'bottom_left' => 'Bottom left', 'bottom_center' => 'Bottom center', 'bottom_right' => 'Bottom right',
    ];

    public const TYPES = [
        'text' => 'Text line (headline / name)',
        'clock' => 'Clock / date',
        'ticker' => 'Scrolling ticker (news bar)',
        'image' => 'Logo / image',
        'box' => 'Colour bar (background strip)',
    ];

    /** Directory holding the live-reloadable text files for one overlay. */
    public function textDirectory(Overlay $overlay): string
    {
        $dir = storage_path('app/private/overlays/'.$overlay->id);
        File::ensureDirectoryExists($dir, 0775, true);

        return $dir;
    }

    /** Writes every text element to disk; called on save so running streams pick it up. */
    public function syncTextFiles(Overlay $overlay): void
    {
        $dir = $this->textDirectory($overlay);
        foreach ($overlay->elements() as $i => $element) {
            if (! in_array($element['type'], ['text', 'ticker'], true)) {
                continue;
            }
            $file = $dir.'/'.$this->slotName($i).'.txt';
            $text = (string) ($element['text'] ?? '');
            if ($element['type'] === 'ticker') {
                $text = str_replace(["\r", "\n"], ' ', $text);
            }
            // Atomic write so FFmpeg never reads a half-written file
            $tmp = $file.'.tmp';
            File::put($tmp, $text);
            @rename($tmp, $file);
        }
    }

    public function slotName(int $index): string
    {
        return 'el'.$index;
    }

    /**
     * The TrueType font used for text.
     *
     * A font ships with the application so overlays work on hosts whose open_basedir
     * hides /usr/share/fonts; the system locations are only a fallback and every probe
     * is guarded, because is_file() *throws* when a path is outside open_basedir.
     */
    public function font(): ?string
    {
        $configured = (string) config('akstream.overlay.font', '');
        if ($configured !== '' && self::readable($configured)) {
            return $configured;
        }

        foreach ([
            resource_path('fonts/overlay.ttf'),
            resource_path('fonts/overlay-regular.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/gnu-free/FreeSans.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
        ] as $candidate) {
            if (self::readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** is_file()/is_readable() raise an ErrorException under open_basedir – never let that escape. */
    public static function readable(string $path): bool
    {
        try {
            return @is_file($path) && @is_readable($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the -filter_complex value. Returns null when the overlay renders nothing.
     *
     * @return array{filter: string, inputs: array<int, string>}|null
     */
    public function build(Overlay $overlay): ?array
    {
        $elements = $overlay->elements();
        if ($elements === []) {
            return null;
        }

        $this->syncTextFiles($overlay);
        $font = $this->font();
        $dir = $this->textDirectory($overlay);
        $w = $this->frameWidth = $overlay->width();
        $h = $this->frameHeight = $overlay->height();

        $inputs = [];          // extra -i files (images)
        $chain = [];           // filters applied to the video
        $overlays = [];        // [inputIndex, x, y, scaleW] for image elements
        $label = '[v]';

        foreach ($elements as $i => $el) {
            $type = (string) $el['type'];
            $size = self::hasPercent($el, 'size')
                ? max(8, (int) round($this->frameHeight * self::percent($el, 'size') / 100))
                : max(8, (int) ($el['size'] ?? 42));

            if ($type === 'image') {
                $path = $this->imagePath($el['image'] ?? null);
                if (! $path) {
                    continue;
                }
                $inputs[] = $path;
                // The overlay filter exposes W/H for the video and w/h for the logo
                [$ox, $oy] = $this->position($el, 'overlay');
                $overlays[] = ['index' => count($inputs), 'x' => $ox, 'y' => $oy, 'width' => $this->elementWidth($el, 200), 'opacity' => $this->opacity($el)];

                continue;
            }

            if ($type === 'box') {
                $boxWidth = self::hasPercent($el, 'w') ? (string) $this->elementWidth($el, 200) : (string) ($el['width'] ?? 'iw');
                $boxHeight = $this->elementHeight($el, 90);
                [$bx, $by] = $this->position($el, 'box', $boxWidth, (string) $boxHeight);
                $chain[] = sprintf(
                    'drawbox=x=%s:y=%s:w=%s:h=%d:color=%s@%.2f:t=fill',
                    $bx, $by, $boxWidth, $boxHeight,
                    $this->color($el['color'] ?? 'black'), $this->opacity($el)
                );

                continue;
            }

            [$x, $y] = $this->position($el, 'text');

            if ($font === null) {
                continue; // no usable font: skip text rather than crash the encoder
            }

            $common = sprintf(
                'fontfile=%s:fontsize=%d:fontcolor=%s@%.2f%s',
                $this->escapePath($font), $size,
                $this->color($el['color'] ?? 'white'), $this->opacity($el),
                ! empty($el['background']) ? sprintf(':box=1:boxcolor=%s@%.2f:boxborderw=%d', $this->color($el['background']), (float) ($el['background_opacity'] ?? 0.55), max(4, (int) ($size / 4))) : ''
            );

            if ($type === 'clock') {
                $format = $this->clockFormat((string) ($el['format'] ?? 'H:i:s'));
                $chain[] = sprintf("drawtext=%s:text='%s':x=%s:y=%s", $common, $format, $x, $y);

                continue;
            }

            $file = $dir.'/'.$this->slotName($i).'.txt';
            if (! self::readable($file)) {
                File::put($file, (string) ($el['text'] ?? ''));
            }

            if ($type === 'ticker') {
                $speed = max(20, (int) ($el['speed'] ?? 120));
                // Scroll right-to-left, restarting when the text has fully left the frame
                $chain[] = sprintf(
                    'drawtext=%s:textfile=%s:reload=1:x=w-mod(%d*t\\,w+tw):y=%s',
                    $common, $this->escapePath($file), $speed, $y
                );

                continue;
            }

            $chain[] = sprintf('drawtext=%s:textfile=%s:reload=1:x=%s:y=%s', $common, $this->escapePath($file), $x, $y);
        }

        if ($chain === [] && $overlays === []) {
            return null;
        }

        // [0:v] -> scale -> drawbox/drawtext -> image overlays -> [vout]
        $filter = sprintf('[0:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1', $w, $h, $w, $h);
        if ($chain !== []) {
            $filter .= ','.implode(',', $chain);
        }
        $filter .= '[base]';
        $current = '[base]';

        foreach ($overlays as $n => $ov) {
            $scaled = "[logo$n]";
            $out = $n === count($overlays) - 1 ? '[vout]' : "[step$n]";
            $filter .= sprintf(';[%d:v]scale=%d:-1,format=rgba,colorchannelmixer=aa=%.2f%s', $ov['index'], $ov['width'], $ov['opacity'], $scaled);
            $filter .= sprintf(';%s%soverlay=%s:%s%s', $current, $scaled, $ov['x'], $ov['y'], $out);
            $current = $out;
        }

        if ($overlays === []) {
            $filter = str_replace('[base]', '[vout]', $filter);
        }

        return ['filter' => $filter, 'inputs' => $inputs];
    }

    /** FFmpeg arguments for the single branded encode. */
    public function encodeArguments(Overlay $overlay, array $built): array
    {
        $args = [];
        foreach ($built['inputs'] as $file) {
            $args[] = '-i';
            $args[] = $file;
        }

        return array_merge($args, [
            '-filter_complex', $built['filter'],
            '-map', '[vout]', '-map', '0:a?',
            '-c:v', 'libx264', '-preset', (string) $overlay->preset, '-tune', 'zerolatency',
            '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-b:v', $overlay->bitrate_kbps.'k', '-maxrate', $overlay->bitrate_kbps.'k', '-bufsize', ($overlay->bitrate_kbps * 2).'k',
            '-r', (string) $overlay->fps, '-g', (string) ($overlay->fps * 2), '-keyint_min', (string) $overlay->fps, '-sc_threshold', '0',
            '-c:a', 'aac', '-b:a', '160k', '-ar', '44100',
        ]);
    }

    /**
     * Position expressions for the filter that will consume them.
     *
     * The editor stores x/y/w/h as percentages of the frame, so a layout survives a
     * change of output resolution. Older overlays that only have a named position
     * (bottom_left …) still work through the fallback below.
     *
     *  text    drawtext : tw/th are the text size
     *  box     drawbox  : has no tw/th, so the element's own width/height are used
     *  overlay overlay  : W/H is the video, w/h is the logo
     *
     * @return array{0:string,1:string}
     */
    private function position(array $el, string $kind, string $elementWidth = '0', string $elementHeight = '0'): array
    {
        $frameW = $this->frameWidth;
        $frameH = $this->frameHeight;

        // Exact placement from the visual editor
        if (self::hasPercent($el, 'x') && self::hasPercent($el, 'y')) {
            return [
                (string) (int) round($frameW * self::percent($el, 'x') / 100),
                (string) (int) round($frameH * self::percent($el, 'y') / 100),
            ];
        }

        // Legacy absolute pixels
        if (isset($el['x'], $el['y']) && $el['x'] !== '' && $el['y'] !== '') {
            return [(string) (int) $el['x'], (string) (int) $el['y']];
        }

        $margin = max(0, (int) ($el['margin'] ?? 40));
        $position = (string) ($el['position'] ?? 'bottom_left');

        if ($kind === 'box' && $elementWidth === 'iw') {
            $x = '0';
        } else {
            [$own, $frame] = match ($kind) {
                'text' => ['tw', 'w'],
                'box' => [$elementWidth, 'iw'],
                default => ['w', 'W'],
            };

            $x = match (true) {
                str_ends_with($position, '_left') => (string) $margin,
                str_ends_with($position, '_right') => sprintf('%s-%s-%d', $frame, $own, $margin),
                default => sprintf('(%s-%s)/2', $frame, $own),
            };
        }

        [$ownH, $frameHExpr] = match ($kind) {
            'text' => ['th', 'h'],
            'box' => [$elementHeight, 'ih'],
            default => ['h', 'H'],
        };

        $y = match (true) {
            str_starts_with($position, 'top') => (string) $margin,
            str_starts_with($position, 'bottom') => sprintf('%s-%s-%d', $frameHExpr, $ownH, $margin),
            default => sprintf('(%s-%s)/2', $frameHExpr, $ownH),
        };

        return [$x, $y];
    }

    /** Width in output pixels for an element sized by the editor. */
    private function elementWidth(array $el, int $fallback): int
    {
        if (self::hasPercent($el, 'w')) {
            return max(8, (int) round($this->frameWidth * self::percent($el, 'w') / 100));
        }

        $legacy = $el['width'] ?? null;

        return is_numeric($legacy) ? max(8, (int) $legacy) : $fallback;
    }

    private function elementHeight(array $el, int $fallback): int
    {
        if (self::hasPercent($el, 'h')) {
            return max(2, (int) round($this->frameHeight * self::percent($el, 'h') / 100));
        }

        $legacy = $el['height'] ?? null;

        return is_numeric($legacy) ? max(2, (int) $legacy) : $fallback;
    }

    private static function hasPercent(array $el, string $key): bool
    {
        return isset($el[$key.'_pct']) && is_numeric($el[$key.'_pct']);
    }

    private static function percent(array $el, string $key): float
    {
        return max(-50.0, min(150.0, (float) $el[$key.'_pct']));
    }

    private function clockFormat(string $format): string
    {
        $c = '\\\\\\:';   // a colon that survives all three parsing passes

        $map = [
            'H:i:s' => '%H'.$c.'%M'.$c.'%S',
            'H:i' => '%H'.$c.'%M',
            'h:i A' => '%I'.$c.'%M %p',
            'd-m-Y' => '%d-%m-%Y',
            'd-m-Y H:i' => '%d-%m-%Y %H'.$c.'%M',
            'D, d M Y' => '%a, %d %b %Y',
        ];

        return '%{localtime\\:'.($map[$format] ?? $map['H:i:s']).'}';
    }

    private function color(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#?[0-9A-Fa-f]{6}$/', $value)
            ? '0x'.ltrim($value, '#')
            : (preg_match('/^[a-zA-Z]+$/', $value) ? strtolower($value) : 'white');
    }

    private function opacity(array $el): float
    {
        return max(0.0, min(1.0, (float) ($el['opacity'] ?? 1)));
    }

    private function imagePath(?string $stored): ?string
    {
        if (! $stored) {
            return null;
        }
        $disk = Storage::disk('local');

        return $disk->exists($stored) ? $disk->path($stored) : null;
    }

    /** Escape the characters FFmpeg's filter parser treats specially inside a value. */
    private function escapePath(string $path): string
    {
        return str_replace(['\\', ':', "'", ','], ['\\\\', '\\:', "\\'", '\\,'], $path);
    }
}
