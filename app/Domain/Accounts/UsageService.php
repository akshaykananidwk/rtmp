<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Settings\SettingsService;
use App\Models\Recording;
use App\Models\StreamSession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What an account has used this calendar month, and whether it may stream more.
 *
 * Minutes are the meaningful unit for streaming, and a live session counts from the
 * moment it started — otherwise an account could run one endless stream and never be
 * measured until it stopped.
 */
class UsageService
{
    public const MONTHLY_MINUTES = 'max_monthly_minutes';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PlanLimits $limits,
    ) {}

    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth();
    }

    /** 0 means no cap at all. */
    public function monthlyMinuteAllowance(): int
    {
        return max(0, (int) $this->settings->get('registration', self::MONTHLY_MINUTES, 0));
    }

    public function minutesUsed(): int
    {
        $seconds = 0;

        foreach (StreamSession::where('started_at', '>=', $this->periodStart())->get(['started_at', 'ended_at', 'duration_seconds']) as $session) {
            $seconds += $session->ended_at !== null
                ? (int) $session->duration_seconds
                // Still running: count what it has used so far.
                : max(0, now()->diffInSeconds($session->started_at, true));
        }

        return (int) floor($seconds / 60);
    }

    /** @return array<string, int|float|null> */
    public function summary(): array
    {
        $sessions = StreamSession::where('started_at', '>=', $this->periodStart());
        $allowance = $this->monthlyMinuteAllowance();
        $used = $this->minutesUsed();

        return [
            'period_start' => $this->periodStart()->toDateString(),
            'streams' => (clone $sessions)->count(),
            'minutes_used' => $used,
            'minutes_allowed' => $allowance,
            'minutes_left' => $allowance === 0 ? null : max(0, $allowance - $used),
            'percent_used' => $allowance === 0 ? null : min(100, (int) round($used / max(1, $allowance) * 100)),
            'bytes_sent' => (int) (clone $sessions)->sum('bytes_sent'),
            'bytes_received' => (int) (clone $sessions)->sum('bytes_received'),
            'recordings' => Recording::count(),
            'recording_bytes' => (int) Recording::sum('size_bytes'),
        ];
    }

    public function exceeded(?User $user): bool
    {
        $allowance = $this->monthlyMinuteAllowance();

        if ($allowance === 0 || $this->limits->exempt($user)) {
            return false;
        }

        return $this->minutesUsed() >= $allowance;
    }

    public function exceededMessage(): string
    {
        return 'This account has used its '.$this->monthlyMinuteAllowance().' streaming minutes for '
            .$this->periodStart()->format('F').'. The allowance resets on the 1st, or the administrator can raise it.';
    }
}
