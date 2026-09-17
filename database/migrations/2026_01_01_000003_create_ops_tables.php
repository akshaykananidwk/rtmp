<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 16);                       // files | database | full
            $table->string('trigger', 16)->default('manual'); // manual | scheduled | update
            $table->string('status', 16)->default('pending'); // pending | running | completed | failed | deleted
            $table->string('disk', 32)->default('local');
            $table->string('location', 1024)->nullable();
            $table->string('db_location', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->string('db_checksum', 64)->nullable();
            $table->boolean('encrypted')->default(false);
            $table->boolean('verified')->default(false);
            $table->string('app_version', 32)->nullable();
            $table->string('git_commit', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('updates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('state', 24)->default('idle');   // state machine
            $table->string('status', 24)->default('checking');
            $table->string('previous_version', 32)->nullable();
            $table->string('version', 32)->nullable();
            $table->string('previous_commit', 64)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('commit_message', 1024)->nullable();
            $table->string('commit_author')->nullable();
            $table->timestamp('commit_date')->nullable();
            $table->string('repository')->nullable();
            $table->string('branch', 128)->nullable();
            $table->unsignedInteger('changed_files')->default(0);
            $table->unsignedInteger('added_files')->default(0);
            $table->unsignedInteger('modified_files')->default(0);
            $table->unsignedInteger('deleted_files')->default(0);
            $table->unsignedBigInteger('download_size')->default(0);
            $table->string('archive_checksum', 64)->nullable();
            $table->string('release_path', 1024)->nullable();
            $table->string('previous_release_path', 1024)->nullable();
            $table->foreignUlid('backup_id')->nullable();
            $table->boolean('backup_ok')->default(false);
            $table->boolean('migration_ok')->nullable();
            $table->boolean('migration_ran')->default(false);
            $table->boolean('health_ok')->nullable();
            $table->boolean('rolled_back')->default(false);
            $table->text('error')->nullable();
            $table->json('release_notes')->nullable();
            $table->json('file_changes')->nullable();
            $table->foreignUlid('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamps();
            $table->index('state');
        });

        Schema::create('update_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('update_id')->constrained('updates')->cascadeOnDelete();
            $table->string('level', 12)->default('info');
            $table->string('step', 32)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('update_protected_paths', function (Blueprint $table) {
            $table->id();
            $table->string('path', 512)->unique();
            $table->boolean('is_default')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'update_protected_paths', 'update_logs', 'updates', 'backups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
