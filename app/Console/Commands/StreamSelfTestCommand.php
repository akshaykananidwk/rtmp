<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\UsageService;
use App\Domain\Destinations\DestinationReachability;
use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Domain\Streaming\Relay\SupervisorRequirements;
use App\Domain\Streaming\SupervisorStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use App\Models\Tenant;
use App\Support\SecretMasker;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Pushes a real synthetic broadcast through this server and reports what worked.
 *
 * Everything else in the test suite runs against fakes. This exercises the actual chain
 * on the actual machine — ffmpeg, MediaMTX, the ingest hooks, the supervisor, the relays
 * — so the operator can tell whether the install is sound without setting up OBS.
 */
class StreamSelfTestCommand extends Command
{
    protected $signature = 'stream:selftest
        {--seconds=25 : How long to publish the test video}
        {--key= : Stream key slug or id to publish to (default: the first enabled one)}
        {--distribute : Also start the configured destinations and check they go live}';

    protected $description = 'Publish a test broadcast to this server and verify the whole pipeline.';

    /** @var array<int, array{name:string, ok:bool, detail:string}> */
    private array $results = [];

    private ?Process $publisher = null;

    private ?float $startedAt = null;

    public function handle(StreamEngineInterface $engine, TenantContext $tenant): int
    {
        $this->line('');
        $this->info('  Self-test — publishing a real test broadcast to this server');
        $this->line('  '.str_repeat('─', 66));

        try {
            $endpoint = $this->resolveEndpoint($tenant);
            if (! $endpoint) {
                return self::FAILURE;
            }

            $this->checkRequirements();
            $this->checkEngine($engine);
            $this->checkSupervisor();

            $path = 'live/'.$endpoint->plainKey();

            // Nothing downstream can be judged if the broadcast never left, and waiting for
            // a stream that was never sent only makes the report slower and less honest.
            if ($this->publish($engine, $path)) {
                $this->checkEngineSeesTheStream($engine, $path);
                $session = $this->checkSessionWasCreated($endpoint);

                // Branding first: the relays switch to the branded stream once it is up, so
                // this is the order things really happen in.
                if ($session && $session->overlay_id) {
                    $this->checkOverlay($session);
                }

                if ($session && $this->option('distribute')) {
                    $this->checkDistribution($session);
                }
            }
        } finally {
            $this->holdThenStop();
        }

        return $this->report();
    }

    private function resolveEndpoint(TenantContext $tenant): ?StreamEndpoint
    {
        $query = StreamEndpoint::withoutGlobalScopes()->where('is_enabled', true);

        if ($key = $this->option('key')) {
            $query->where(fn ($q) => $q->where('slug', $key)->orWhere('id', $key));
        }

        $endpoint = $query->orderBy('created_at')->first();

        if (! $endpoint) {
            $this->error('  No enabled stream key found'.($this->option('key') ? ' matching "'.$this->option('key').'".' : '. Create one in Stream Keys first.'));

            return null;
        }

        if (! $tenant->has()) {
            // Console runs outside a request, so nothing has set the tenant yet.
            $tenant->set(Tenant::find($endpoint->tenant_id));
        }

        $this->line('  Stream key: <options=bold>'.$endpoint->name.'</> (key hidden)');
        $this->line('');

        return $endpoint;
    }

    private function checkRequirements(): void
    {
        $blockers = SupervisorRequirements::blockers();

        $this->record('PHP can run FFmpeg', $blockers === [], $blockers === []
            ? 'ffmpeg found at '.(SupervisorRequirements::ffmpeg() ?? '?')
            : implode(' ', $blockers));
    }

    private function checkEngine(StreamEngineInterface $engine): void
    {
        $reachable = $engine->isReachable();
        $this->record('Media server reachable', $reachable, $reachable
            ? $engine->name().' answers its API'
            : 'MediaMTX is not answering — systemctl status mediamtx');
    }

    private function checkSupervisor(): void
    {
        $status = app(SupervisorStatus::class);
        $this->record('Relay supervisor running', $status->isRunning(), $status->isRunning()
            ? 'last pass '.$status->secondsSinceBeat().'s ago'
            : (string) $status->problem());
    }

    /** @return bool whether the test broadcast is actually on the wire */
    private function publish(StreamEngineInterface $engine, string $path): bool
    {
        $binary = SupervisorRequirements::ffmpeg();
        if ($binary === null) {
            $this->record('Test broadcast accepted', false, 'ffmpeg is not available');

            return false;
        }

        $seconds = max(5, (int) $this->option('seconds'));
        // The checks below can take longer than the hold time the operator asked for, and a
        // check that outlives the broadcast reports a failure that never happened. Allow a
        // generous ceiling and stop the publisher ourselves once the report is done.
        $limit = $seconds + 150;
        $target = $engine->internalSourceUrl($path);

        // Colour bars plus a tone: a real H.264/AAC stream, with nothing to install.
        $this->publisher = new Process([
            $binary, '-hide_banner', '-loglevel', 'error', '-nostdin',
            '-re', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100',
            '-t', (string) $limit,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency', '-pix_fmt', 'yuv420p', '-g', '60', '-b:v', '2000k',
            '-c:a', 'aac', '-b:a', '128k',
            '-f', 'flv', $target,
        ]);
        $this->publisher->setTimeout(null);
        $this->publisher->start();
        $this->startedAt = microtime(true);

        $this->line('  Publishing '.$seconds.'s of colour bars and tone…');
        sleep(6);

        $running = $this->publisher->isRunning();
        $this->record('Test broadcast accepted', $running, $running
            ? 'ffmpeg is publishing to the ingest'
            : 'ffmpeg exited: '.SecretMasker::maskString(trim($this->publisher->getErrorOutput()) ?: 'no output'));

        return $running;
    }

    private function checkEngineSeesTheStream(StreamEngineInterface $engine, string $path): void
    {
        $found = null;

        for ($i = 0; $i < 10 && $found === null; $i++) {
            $info = $engine->getPath($path);
            if (($info['ready'] ?? false) === true) {
                $found = $info;
                break;
            }
            sleep(1);
        }

        $this->record('Media server received it', $found !== null, $found !== null
            ? 'path is ready, tracks: '.implode(', ', $found['tracks'] ?? [])
            : 'the media server never reported the path ready — check its log and that the ingest port is open');
    }

    private function checkSessionWasCreated(StreamEndpoint $endpoint): ?StreamSession
    {
        $session = null;

        for ($i = 0; $i < 12 && $session === null; $i++) {
            $session = StreamSession::withoutGlobalScopes()
                ->where('stream_endpoint_id', $endpoint->id)
                ->whereIn('status', ['detected', 'live'])
                ->latest('started_at')
                ->first();
            if ($session === null) {
                sleep(1);
            }
        }

        // The hook is what tells the panel a stream exists; without it nothing else runs.
        $this->record('Panel detected the stream', $session !== null, $session !== null
            ? 'session '.$session->id.' is '.$session->status
            : 'the media server did not call back — check STREAM_ENGINE_SECRET and that it can reach this site');

        return $session;
    }

    private function checkDistribution(StreamSession $session): void
    {
        $usage = app(UsageService::class);
        if ($usage->exceeded(null)) {
            $this->record('Destinations went live', false, $usage->exceededMessage());

            return;
        }

        $created = app(DistributionService::class)->start($session);

        if ($created->isEmpty()) {
            $this->record('Destinations went live', false, 'no enabled destination is configured for this stream key');

            return;
        }

        $live = 0;
        $errors = [];

        for ($i = 0; $i < 20; $i++) {
            sleep(1);
            $rows = StreamSessionDestination::withoutGlobalScopes()->where('stream_session_id', $session->id)->get();
            $live = $rows->where('status', 'live')->count();
            $errors = $rows->whereNotNull('last_error')->pluck('last_error')->all();
            if ($live === $rows->count()) {
                break;
            }
        }

        $total = StreamSessionDestination::withoutGlobalScopes()->where('stream_session_id', $session->id)->count();
        $this->record('Destinations went live', $live === $total && $total > 0, $live.' of '.$total.' live'
            .($live < $total && ! $this->stillPublishing() ? ' — the test broadcast ended first, re-run with a longer --seconds' : ''));

        if ($live < $total) {
            $this->diagnoseDestinations($session);
        }
    }

    /**
     * Say why each destination failed in terms the operator can act on.
     *
     * FFmpeg reports a blocked firewall, a broken TLS chain and a rejected stream key all
     * as "Operation not permitted", so the raw error alone is not worth printing.
     */
    private function diagnoseDestinations(StreamSession $session): void
    {
        $this->line('');
        $this->line('  Why each destination failed:');

        $rows = StreamSessionDestination::withoutGlobalScopes()
            ->with(['destination' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('stream_session_id', $session->id)
            ->get();

        foreach ($rows as $sd) {
            $name = $sd->destination?->name ?? 'Destination';

            if ($sd->status === 'live') {
                $this->line('    <fg=green>✓</> '.$name.' — live');

                continue;
            }

            $url = $sd->destination?->rtmp_url;
            if (! $url) {
                $this->line('    <fg=red>✗</> '.$name.' — no RTMP URL configured');

                continue;
            }

            $reach = app(DestinationReachability::class)->check($url);
            $this->line('    <fg=red>✗</> '.$name.' — '.$reach['message']);

            if ($sd->last_error) {
                $this->line('        ffmpeg said: '.SecretMasker::maskString(mb_substr($sd->last_error, 0, 160)));
            }
        }

        $this->line('');
    }

    private function checkOverlay(StreamSession $session): void
    {
        $status = null;

        for ($i = 0; $i < 15; $i++) {
            sleep(1);
            $status = StreamSession::withoutGlobalScopes()->where('id', $session->id)->value('branding_status');
            if ($status === 'live') {
                break;
            }
        }

        $this->record('Overlay rendered into the stream', $status === 'live', match (true) {
            $status === 'live' => 'the overlay encoder is publishing the branded stream',
            ! $this->stillPublishing() => 'the test broadcast ended before the encoder came up — re-run with a longer --seconds',
            default => 'branding status is "'.($status ?: 'none').'" — see Live Logs for the encoder error',
        });
    }

    /** True while the test source is still on air. */
    private function stillPublishing(): bool
    {
        return $this->publisher !== null && $this->publisher->isRunning();
    }

    private function holdThenStop(): void
    {
        if ($this->publisher === null) {
            return;
        }

        $this->startedAt ??= microtime(true);
        $remaining = (int) ceil(max(5, (int) $this->option('seconds')) - (microtime(true) - $this->startedAt));

        if ($remaining > 0 && $this->stillPublishing()) {
            $this->line('  Holding the broadcast for another '.$remaining.'s so you can watch the panel…');
            sleep($remaining);
        }

        if ($this->stillPublishing()) {
            $this->publisher->stop(5, defined('SIGTERM') ? SIGTERM : 15);
        }

        $this->line('  Test broadcast stopped.');
    }

    private function record(string $name, bool $ok, string $detail): void
    {
        $this->results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        $this->line(sprintf('  %s %-34s %s', $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $name, $detail));
    }

    private function report(): int
    {
        $failed = array_values(array_filter($this->results, fn (array $r) => ! $r['ok']));

        $this->line('  '.str_repeat('─', 66));

        if ($failed === []) {
            $this->info('  All '.count($this->results).' checks passed — this server can take a stream and pass it on.');

            return self::SUCCESS;
        }

        $this->error('  '.count($failed).' of '.count($this->results).' checks failed:');
        foreach ($failed as $failure) {
            $this->line('    • <options=bold>'.$failure['name'].'</> — '.$failure['detail']);
        }

        return self::FAILURE;
    }
}
