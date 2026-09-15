<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint ) {
            ->uuid('id')->primary();
            ->string('client_id')->nullable();
            ->string('idempotency_key')->nullable()->index();
            ->string('method', 10);
            ->text('url');
            ->json('headers')->nullable();
            ->text('body')->nullable();
            ->string('status')->default('pending')->index();
            ->integer('attempts')->default(0);
            ->timestamp('last_attempt_at')->nullable();
            ->timestamp('next_attempt_at')->nullable();
            ->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
