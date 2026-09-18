<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Streaming\Relay\SupervisorRequirements;
use Tests\TestCase;

class SupervisorRequirementsTest extends TestCase
{
    public function test_signal_handling_needs_every_piece_not_just_the_first(): void
    {
        // The crash this guards against: pcntl_async_signals allowed while pcntl_signal is in
        // disable_functions. Checking only the first one made the supervisor die on startup.
        $this->assertContains('pcntl_signal', SupervisorRequirements::OPTIONAL_FUNCTIONS);
        $this->assertContains('pcntl_async_signals', SupervisorRequirements::OPTIONAL_FUNCTIONS);

        foreach (SupervisorRequirements::OPTIONAL_FUNCTIONS as $fn) {
            if (! function_exists($fn)) {
                $this->assertFalse(SupervisorRequirements::canHandleSignals(), $fn.' is unavailable, so signals must be off');

                return;
            }
        }

        $this->assertSame(defined('SIGTERM'), SupervisorRequirements::canHandleSignals());
    }

    public function test_spawning_a_relay_requires_the_process_functions(): void
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $fn) {
            $this->assertContains($fn, SupervisorRequirements::REQUIRED_FUNCTIONS);
        }

        // Detection is plain function_exists, which is what disable_functions turns off.
        $this->assertSame([], SupervisorRequirements::missing(['strlen', 'count']));
        $this->assertSame(['ak_not_a_function'], SupervisorRequirements::missing(['strlen', 'ak_not_a_function']));
    }

    public function test_the_disabled_function_message_names_them_and_the_remedy(): void
    {
        $one = SupervisorRequirements::disabledFunctionsMessage(['proc_open']);
        $this->assertStringContainsString('proc_open is disabled', $one);
        $this->assertStringContainsString('Remove it from disable_functions', $one);
        $this->assertStringContainsString('aaPanel', $one);

        $many = SupervisorRequirements::disabledFunctionsMessage(['proc_open', 'proc_close']);
        $this->assertStringContainsString('proc_open, proc_close are disabled', $many);
        $this->assertStringContainsString('Remove them', $many);
    }

    public function test_a_missing_ffmpeg_is_reported_as_a_blocker(): void
    {
        config(['akstream.streaming.ffmpeg' => '/nonexistent/ffmpeg-'.uniqid()]);

        $blockers = SupervisorRequirements::blockers();
        $this->assertNotEmpty($blockers);
        $this->assertStringContainsString('FFmpeg', implode(' ', $blockers));

        config(['akstream.streaming.ffmpeg' => base_path('tests/Fixtures/fake-ffmpeg.sh')]);
        $this->assertSame([], SupervisorRequirements::blockers(), 'nothing is missing on a healthy host');
    }
}
