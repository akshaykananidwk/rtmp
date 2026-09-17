<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_endpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 64);                       // MediaMTX path name (public, non-secret)
            $table->string('key_hash', 64)->unique();          // sha256 of the secret stream key (used for auth lookups)
            $table->text('key_encrypted');                     // encrypted plaintext (needed to show/copy once + engine config)
            $table->string('key_hint', 12);                    // last 4 chars for display
            $table->boolean('is_enabled')->default(true);
            $table->boolean('auto_distribute')->default(false);
            $table->boolean('record_enabled')->default(false);
            $table->string('status', 24)->default('offline');  // offline | live
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 32)->index();  // youtube | facebook | twitch | linkedin
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->json('meta')->nullable();               // channels / pages list (non-secret)
            $table->string('status', 24)->default('connected');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'platform']);
        });

        Schema::create('platform_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('platform_account_id')->constrained('platform_accounts')->cascadeOnDelete();
            $table->string('token_type', 24)->default('bearer');
            $table->text('access_token_encrypted');
            $table->text('refresh_token_encrypted')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stream_destinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('stream_endpoint_id')->nullable()->constrained('stream_endpoints')->nullOnDelete();
            $table->foreignUlid('platform_account_id')->nullable()->constrained('platform_accounts')->nullOnDelete();
            $table->string('platform', 32)->index();       // custom_rtmp | youtube | facebook | twitch | linkedin | instagram
            $table->string('name');
            $table->string('account_name')->nullable();
            $table->string('connection_method', 24)->default('rtmp'); // rtmp | oauth
            $table->string('rtmp_url', 2048)->nullable();
            $table->text('stream_key_encrypted')->nullable();
            $table->string('stream_key_hint', 8)->nullable();
            $table->json('options')->nullable();            // platform-specific (title, privacy, page id, broadcast id ...)
            $table->boolean('is_enabled')->default(true);
            $table->string('status', 24)->default('idle');  // idle | connecting | connected | live | reconnecting | failed | disabled
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result', 24)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'is_enabled']);
        });

        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('stream_endpoint_id')->constrained('stream_endpoints')->cascadeOnDelete();
            $table->foreignUlid('scheduled_stream_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status', 24)->default('detected'); // detected | live | ended | failed
            $table->string('node_id', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('distribution_started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('incoming_bitrate_kbps')->default(0);
            $table->unsignedInteger('outgoing_bitrate_kbps')->default(0);
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);
            $table->string('resolution', 24)->nullable();
            $table->decimal('fps', 6, 2)->nullable();
            $table->string('video_codec', 24)->nullable();
            $table->string('audio_codec', 24)->nullable();
            $table->boolean('recording_enabled')->default(false);
            $table->string('recording_path', 1024)->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index('started_at');
        });

        Schema::create('stream_session_destinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('stream_session_id')->constrained('stream_sessions')->cascadeOnDelete();
            $table->foreignUlid('stream_destination_id')->constrained('stream_destinations')->cascadeOnDelete();
            $table->string('desired_state', 16)->default('running'); // running | stopped
            $table->string('status', 24)->default('pending'); // pending | connecting | connected | live | reconnecting | failed | stopped
            $table->string('node_id', 64)->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedBigInteger('bytes_sent')->default(0);
            $table->unsignedInteger('outgoing_bitrate_kbps')->default(0);
            $table->json('platform_meta')->nullable();       // broadcast id, live video id, watch URL
            $table->timestamps();
            $table->index(['stream_session_id', 'status']);
            $table->index(['desired_state', 'node_id']);
        });

        Schema::create('stream_destination_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->index();
            $table->foreignUlid('stream_session_id')->nullable()->index();
            $table->foreignUlid('stream_destination_id')->nullable()->index();
            $table->string('level', 12)->default('info');  // info | warning | error
            $table->string('event', 64)->index();
            $table->string('message', 1024);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });

        Schema::create('scheduled_streams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('stream_endpoint_id')->constrained('stream_endpoints')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('auto_stop_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->boolean('auto_start')->default(true);
            $table->boolean('auto_stop')->default(false);
            $table->boolean('recording_enabled')->default(false);
            $table->string('thumbnail_path', 1024)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('scheduled'); // scheduled | waiting_for_source | live | completed | cancelled | missed
            $table->foreignUlid('stream_session_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        Schema::create('scheduled_stream_destination', function (Blueprint $table) {
            $table->foreignUlid('scheduled_stream_id')->constrained('scheduled_streams')->cascadeOnDelete();
            $table->foreignUlid('stream_destination_id')->constrained('stream_destinations')->cascadeOnDelete();
            $table->primary(['scheduled_stream_id', 'stream_destination_id']);
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('stream_session_id')->nullable()->constrained('stream_sessions')->nullOnDelete();
            $table->string('title');
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);                  // never exposed publicly
            $table->string('format', 12)->default('mp4');
            $table->string('resolution', 24)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 24)->default('recording'); // recording | completed | failed | transferring | deleted
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32)->index();
            $table->string('event_type', 64)->nullable();
            $table->string('signature_status', 16)->default('unverified'); // verified | invalid | unsupported
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'recordings', 'scheduled_stream_destination', 'scheduled_streams', 'stream_destination_logs', 'stream_session_destinations', 'stream_sessions', 'stream_destinations', 'platform_tokens', 'platform_accounts', 'stream_endpoints'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
