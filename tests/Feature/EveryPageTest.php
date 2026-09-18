<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\StreamSessionService;
use App\Models\ErrorLog;
use App\Models\Overlay;
use App\Models\Role;
use App\Models\ScheduledStream;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\Update;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Walks every page the panel exposes and fails on any server error.
 *
 * Individual features have their own tests; this is the sweep that catches a page nobody
 * opened since a rename — a missing view, a removed relation, a helper called on null.
 */
class EveryPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return string[] every GET path that takes no parameters */
    private function simplePaths(): array
    {
        $skip = ['up', 'sanctum/csrf-cookie', 'storage/{path}'];
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || in_array($uri, $skip, true)) {
                continue;
            }
            if (str_starts_with($uri, 'api/') || str_starts_with($uri, 'webhooks/') || str_starts_with($uri, '_')) {
                continue;
            }
            $paths[] = '/'.ltrim($uri, '/');
        }

        return array_values(array_unique($paths));
    }

    public function test_every_public_page_renders(): void
    {
        $this->seedSystem();

        foreach ($this->simplePaths() as $path) {
            // The installer redirects away once installed; that is a normal answer, not a fault.
            $response = $this->get($path);
            $this->assertLessThan(500, $response->getStatusCode(), 'GET '.$path.' returned '.$response->getStatusCode());
        }

        $this->assertNoErrorsLogged();
    }

    public function test_every_admin_page_renders_for_an_administrator(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $this->seedContent($tenant, $user);

        foreach ($this->simplePaths() as $path) {
            $response = $this->get($path);
            $this->assertLessThan(500, $response->getStatusCode(), 'GET '.$path.' returned '.$response->getStatusCode());
        }

        $this->assertNoErrorsLogged();
    }

    public function test_every_page_that_takes_a_record_renders(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $ids = $this->seedContent($tenant, $user);

        $paths = [
            '/admin/destinations/'.$ids['destination'].'/edit',
            '/admin/overlays/'.$ids['overlay'].'/edit',
            '/admin/schedules/'.$ids['schedule'].'/edit',
            '/admin/stream-keys/'.$ids['endpoint'].'/edit',
            '/admin/users/'.$user->id.'/edit',
            '/admin/history/'.$ids['session'],
            '/admin/logs/errors/'.$ids['error'],
            '/admin/health/database',
            '/admin/obs-setup/'.$ids['endpoint'],
            '/admin/updates/'.$ids['update'],
            '/admin/updates/'.$ids['update'].'/status',
            '/admin/preview/'.$ids['endpoint'].'/status',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);
            $this->assertLessThan(500, $response->getStatusCode(), 'GET '.$path.' returned '.$response->getStatusCode());
        }

        $this->assertNoErrorsLogged();
    }

    public function test_no_page_breaks_for_a_lesser_role(): void
    {
        $this->seedSystem();
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::SUPER_ADMIN);
        $this->actAsTenant($tenant);
        $this->seedContent($tenant, $admin);

        foreach ([Role::ADMIN, Role::OPERATOR, Role::VIEWER] as $role) {
            $user = $this->makeUser($tenant, $role, ['email' => $role.'@example.com']);
            $this->actingAs($user);

            foreach ($this->simplePaths() as $path) {
                $response = $this->get($path);
                // 403 is a correct answer here; 500 never is.
                $this->assertLessThan(500, $response->getStatusCode(), $role.' GET '.$path.' returned '.$response->getStatusCode());
            }
        }

        $this->assertNoErrorsLogged();
    }

    /** @return array<string,string> */
    private function seedContent($tenant, User $user): array
    {
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $destination = StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        $overlay = Overlay::create([
            'tenant_id' => $tenant->id,
            'name' => 'Audit overlay',
            'resolution' => '1920x1080',
            'elements' => [['type' => 'text', 'text' => 'Hello', 'enabled' => true]],
        ]);
        $schedule = ScheduledStream::create([
            'tenant_id' => $tenant->id,
            'title' => 'Audit schedule',
            'scheduled_at' => now()->addDay(),
            'stream_endpoint_id' => $endpoint->id,
            'created_by' => $user->id,
        ]);
        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        app(DistributionService::class)->start($session, null, $user);
        $error = ErrorLog::create([
            'tenant_id' => $tenant->id,
            'reference' => 'ERR-AUDIT-0001',
            'exception_class' => 'RuntimeException',
            'message' => 'seeded for the audit',
            'file' => 'x.php',
            'line' => 1,
        ]);
        $update = Update::create(['version' => '9.9.9', 'status' => 'completed', 'state' => 'completed', 'started_by' => $user->id]);

        return [
            'endpoint' => $endpoint->id,
            'destination' => $destination->id,
            'overlay' => $overlay->id,
            'schedule' => $schedule->id,
            'session' => $session->id,
            'error' => $error->id,
            'update' => $update->id,
        ];
    }

    private function assertNoErrorsLogged(): void
    {
        $logged = ErrorLog::withoutGlobalScopes()->where('reference', '!=', 'ERR-AUDIT-0001')->get();

        $this->assertCount(0, $logged, 'pages logged errors: '.$logged->pluck('message')->implode(' | '));
    }
}
