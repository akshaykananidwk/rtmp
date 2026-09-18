<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_webhooks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('url', 2048);
            // The signing secret is encrypted at rest and only ever shown once, on creation.
            $table->text('secret_encrypted');
            $table->string('secret_hint', 20)->nullable();
            $table->json('events');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_enabled']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outgoing_webhook_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->boolean('succeeded')->default(false);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['outgoing_webhook_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('outgoing_webhooks');
    }
};
