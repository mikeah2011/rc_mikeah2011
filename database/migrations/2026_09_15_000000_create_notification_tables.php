<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->char('key_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('vendor');
            $table->text('url');
            $table->string('method', 10)->default('POST');
            $table->text('static_headers')->nullable();
            $table->json('allowed_dynamic_headers')->default('[]');
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('max_attempts')->default(8);
            $table->json('backoff_seconds')->default('[5, 30, 120, 600, 1800, 3600]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('api_client_endpoint', function (Blueprint $table): void {
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->primary(['api_client_id', 'endpoint_id']);
        });

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('api_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('endpoint_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 200);
            $table->char('request_hash', 64);
            $table->string('content_type', 150);
            $table->longText('body');
            $table->text('dynamic_headers')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('delivery_round')->default(1);
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['api_client_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
            $table->index(['endpoint_id', 'status']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('delivery_round');
            $table->unsignedInteger('attempt_number');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 20);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['delivery_id', 'delivery_round', 'attempt_number']);
            $table->index(['outcome', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('api_client_endpoint');
        Schema::dropIfExists('endpoints');
        Schema::dropIfExists('api_clients');
    }
};
