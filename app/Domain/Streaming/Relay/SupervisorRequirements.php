<?php

declare(strict_types=1);

namespace App\Domain\Streaming\Relay;

use App\Support\BinaryLocator;

/**
 * What the relay supervisor needs from PHP before it can stream anything.
 *
 * Hosting panels (aaPanel, cPanel, Plesk) ship a long `disable_functions` list that includes
 * exactly the functions used to spawn and supervise FFmpeg. Without this the supervisor dies
 * on an undefined-function fatal, systemd restarts it forever, and the panel only ever shows
 * destinations stuck at "pending" — so check up front and say precisely what to re-enable.
 */
final class SupervisorRequirements
{
    /** Symfony Process cannot start, poll or stop a relay without these. */
    public const REQUIRED_FUNCTIONS = ['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'];

    /** Only needed to shut down gracefully; the supervisor runs fine without them. */
    public const OPTIONAL_FUNCTIONS = ['pcntl_async_signals', 'pcntl_signal'];

    /**
     * @param  string[]  $functions
     * @return string[] the ones PHP will not let us call
     */
    public static function missing(array $functions): array
    {
        return array_values(array_filter($functions, fn ($fn) => ! function_exists($fn)));
    }

    /** @return string[] */
    public static function missingRequired(): array
    {
        return self::missing(self::REQUIRED_FUNCTIONS);
    }

    /** @return string[] */
    public static function missingOptional(): array
    {
        return self::missing(self::OPTIONAL_FUNCTIONS);
    }

    /** @param  string[]  $missing */
    public static function disabledFunctionsMessage(array $missing): string
    {
        $one = count($missing) === 1;

        return 'PHP cannot start FFmpeg: '.implode(', ', $missing).' '.($one ? 'is' : 'are').' disabled. '
            .'Remove '.($one ? 'it' : 'them').' from disable_functions in the CLI php.ini '
            .'(aaPanel: App Store → PHP → Settings → Disabled functions), then restart akstream-supervisor.';
    }

    /** True only when every signal-handling piece is usable, constants included. */
    public static function canHandleSignals(): bool
    {
        return self::missingOptional() === [] && defined('SIGTERM');
    }

    public static function ffmpeg(): ?string
    {
        return BinaryLocator::find((string) config('akstream.streaming.ffmpeg', 'ffmpeg'));
    }

    /**
     * Everything that would stop the supervisor from working, worst first.
     *
     * @return string[]
     */
    public static function blockers(): array
    {
        $problems = [];

        if ($missing = self::missingRequired()) {
            $problems[] = self::disabledFunctionsMessage($missing);
        }

        if (self::ffmpeg() === null) {
            $binary = (string) config('akstream.streaming.ffmpeg', 'ffmpeg');
            $problems[] = BinaryLocator::blockedByOpenBasedir($binary)
                ? 'FFmpeg ('.$binary.') is outside open_basedir, so PHP cannot run it. Add its directory to open_basedir or set FFMPEG_BINARY to a permitted path.'
                : 'FFmpeg was not found. Install it (apt install ffmpeg / yum install ffmpeg) or set FFMPEG_BINARY in .env.';
        }

        return $problems;
    }

    /** @return string[] things that still work but are degraded */
    public static function warnings(): array
    {
        if (self::canHandleSignals()) {
            return [];
        }

        $missing = self::missingOptional() ?: ['SIGTERM'];

        return ['Graceful shutdown is off ('.implode(', ', $missing).' unavailable): relays are killed rather than stopped cleanly on restart.'];
    }
}
