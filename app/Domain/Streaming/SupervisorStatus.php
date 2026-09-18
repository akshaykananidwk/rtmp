<?php

declare(strict_types=1);

namespace App\Domain\Streaming;

use Illuminate\Support\Facades\Cache;

/**
 * Is the relay supervisor actually reconciling?
 *
 * Nothing streams without it: the web request only records the desired state, and
 * `stream:supervisor` is what starts the FFmpeg relays. When it is not running the
 * destinations sit at "pending" forever with no error to show, so the panel reads this
 * and says so instead of leaving the operator staring at a spinner.
 *
 * The supervisor writes a per-node key for diagnostics plus one shared key, because a
 * file/database cache cannot enumerate keys to find the nodes.
 */
final class SupervisorStatus
{
    /** A tick runs every couple of seconds; allow for a slow pass plus clock skew. */
    public const STALE_AFTER_SECONDS = 60;

    public const SHARED_KEY = 'stream:supervisor:heartbeat';

    public static function nodeKey(string $nodeId): string
    {
        return self::SHARED_KEY.':'.$nodeId;
    }

    /** Called by the supervisor after every successful pass. */
    public static function beat(string $nodeId): void
    {
        $at = now()->timestamp;
        $ttl = self::STALE_AFTER_SECONDS * 4;

        Cache::put(self::nodeKey($nodeId), $at, $ttl);
        Cache::put(self::SHARED_KEY, ['node' => $nodeId, 'at' => $at], $ttl);
    }

    /** Unix timestamp of the most recent pass, or null if it has never run (or the cache was cleared). */
    public function lastBeatAt(): ?int
    {
        $beat = Cache::get(self::SHARED_KEY);

        if (is_array($beat) && isset($beat['at'])) {
            return (int) $beat['at'];
        }

        // A supervisor from before this key existed, or a bare integer write.
        return is_numeric($beat) ? (int) $beat : null;
    }

    public function nodeId(): ?string
    {
        $beat = Cache::get(self::SHARED_KEY);

        return is_array($beat) && isset($beat['node']) ? (string) $beat['node'] : null;
    }

    public function secondsSinceBeat(): ?int
    {
        $at = $this->lastBeatAt();

        return $at === null ? null : max(0, now()->timestamp - $at);
    }

    public function isRunning(): bool
    {
        $since = $this->secondsSinceBeat();

        return $since !== null && $since <= self::STALE_AFTER_SECONDS;
    }

    /** What to tell the operator, or null when everything is reconciling normally. */
    public function problem(): ?string
    {
        if ($this->isRunning()) {
            return null;
        }

        $since = $this->secondsSinceBeat();

        return $since === null
            ? 'The relay supervisor has never reported in. Nothing will be sent to any destination until it runs.'
            : 'The relay supervisor last reported '.$since.'s ago. Nothing is being sent to any destination.';
    }
}
