<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\Overlay;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;

/**
 * What a new account still has to do before it can go live.
 *
 * Someone who just signed up lands on a dashboard full of zeros with no idea which of
 * fifteen menu items comes first. Each step below is derived from real records, so the
 * list cannot claim something is done when it is not, and it disappears once it is.
 */
class OnboardingChecklist
{
    /**
     * @return array<int, array{key:string, title:string, detail:string, done:bool, route:?string, label:string}>
     */
    public function steps(): array
    {
        $key = StreamEndpoint::where('is_enabled', true)->first();
        $destinations = StreamDestination::where('is_enabled', true)->count();
        $hasStreamed = StreamSession::query()->exists();

        return [
            [
                'key' => 'stream_key',
                'title' => 'Get your stream key',
                'detail' => 'Copy the Server URL and Stream Key into OBS so this panel can receive your video.',
                'done' => $key !== null,
                'route' => 'admin.obs-setup',
                'label' => 'Open OBS Setup',
            ],
            [
                'key' => 'destination',
                'title' => 'Add where to stream',
                'detail' => 'Connect Facebook or YouTube, or paste an RTMP key. You can add as many as your plan allows.',
                'done' => $destinations > 0,
                'route' => 'admin.destinations.create',
                'label' => 'Add a destination',
            ],
            [
                'key' => 'source',
                'title' => 'Start streaming from OBS',
                'detail' => 'Press "Start Streaming" in OBS. This panel detects the incoming video automatically.',
                'done' => $hasStreamed,
                'route' => 'admin.live',
                'label' => 'Open Live Stream',
            ],
            [
                'key' => 'overlay',
                'title' => 'Add your logo and headlines (optional)',
                'detail' => 'Drag a logo, clock, headline or ticker onto the picture and it goes out with your stream.',
                'done' => Overlay::query()->exists(),
                'route' => 'admin.overlays.create',
                'label' => 'Design an overlay',
            ],
        ];
    }

    /** The first unfinished step, or null when the account is set up. */
    public function nextStep(): ?array
    {
        foreach ($this->steps() as $step) {
            if (! $step['done']) {
                return $step;
            }
        }

        return null;
    }

    /** Only the required steps count towards being finished; the optional one never blocks. */
    public function isComplete(): bool
    {
        foreach ($this->steps() as $step) {
            if ($step['key'] !== 'overlay' && ! $step['done']) {
                return false;
            }
        }

        return true;
    }

    public function completedCount(): int
    {
        return count(array_filter($this->steps(), fn (array $s) => $s['done']));
    }
}
