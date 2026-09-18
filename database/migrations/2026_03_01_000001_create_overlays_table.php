<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overlays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->json('elements')->nullable();      // text / clock / image / ticker / box
            $table->string('resolution', 16)->default('1920x1080');
            $table->unsignedSmallInteger('bitrate_kbps')->default(4500);
            $table->unsignedSmallInteger('fps')->default(30);
            $table->string('preset', 24)->default('veryfast');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'is_default']);
        });

        Schema::table('stream_endpoints', function (Blueprint $table) {
            $table->foreignUlid('overlay_id')->nullable()->after('record_enabled');
        });

        Schema::table('stream_sessions', function (Blueprint $table) {
            $table->string('branding_status', 24)->nullable()->after('recording_path'); // starting | live | failed
            $table->foreignUlid('overlay_id')->nullable()->after('branding_status');
        });
    }

    public function down(): void
    {
        Schema::table('stream_sessions', function (Blueprint $table) {
            $table->dropColumn(['branding_status', 'overlay_id']);
        });
        Schema::table('stream_endpoints', function (Blueprint $table) {
            $table->dropColumn('overlay_id');
        });
        Schema::dropIfExists('overlays');
    }
};
