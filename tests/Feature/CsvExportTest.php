<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Streaming\StreamSessionService;
use App\Models\Role;
use App\Models\StreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvExportTest extends TestCase
{
    use RefreshDatabase;

    private function body(string $url): string
    {
        $response = $this->get($url);
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    public function test_history_audit_and_analytics_download_as_csv(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);

        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Studio key']);
        app(StreamSessionService::class)->onSourceReady($endpoint);

        $history = $this->body('/admin/export/history.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $history, 'Excel needs the byte-order mark to read UTF-8');
        $this->assertStringContainsString('Started,Ended,Title', $history);
        $this->assertStringContainsString('Studio key', $history);

        $audit = $this->body('/admin/export/audit.csv');
        $this->assertStringContainsString('When,User,Action', $audit);
        $this->assertStringContainsString('export.history', $audit, 'exporting is itself an audited action');

        $analytics = $this->body('/admin/export/analytics.csv?days=7');
        $this->assertStringContainsString('Date,Streams,"Duration (s)",Bytes,Errors', $analytics);
        $this->assertSame(7, substr_count(trim($analytics), "\n"), 'a header plus seven days');
        $this->assertStringContainsString(now()->toDateString(), $analytics);
    }

    public function test_an_export_never_carries_another_accounts_rows_or_a_stream_key(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $other = $this->makeTenant('other');
        $otherKey = StreamEndpoint::factory()->create(['tenant_id' => $other->id, 'name' => 'Somebody Else Key']);
        $this->actAsTenant($other);
        app(StreamSessionService::class)->onSourceReady($otherKey);
        $plain = $otherKey->plainKey();

        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $mine = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id, 'name' => 'My Key']);
        app(StreamSessionService::class)->onSourceReady($mine);

        $history = $this->body('/admin/export/history.csv');
        $this->assertStringContainsString('My Key', $history);
        $this->assertStringNotContainsString('Somebody Else Key', $history);
        $this->assertStringNotContainsString($plain, $history, 'a stream key never leaves in an export');

        $this->assertStringNotContainsString($plain, $this->body('/admin/export/audit.csv'));
    }

    public function test_a_viewer_cannot_export_the_audit_log(): void
    {
        $this->seedSystem();
        $tenant = $this->makeTenant();
        $this->actAsTenant($tenant);

        $this->actingAs($this->makeUser($tenant, Role::VIEWER));
        $this->get('/admin/export/audit.csv')->assertForbidden();

        // Analytics is read-only information a viewer already sees on screen.
        $this->get('/admin/export/analytics.csv')->assertOk();
    }
}
