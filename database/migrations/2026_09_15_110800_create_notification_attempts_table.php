<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_attempts', function (Blueprint ) {
            ->bigIncrements('id');
            ->uuid('notification_id')->index();
            ->integer('attempt_number')->default(1);
            ->timestamp('started_at')->nullable();
            ->timestamp('finished_at')->nullable();
            ->integer('http_status')->nullable();
            ->text('error')->nullable();
            ->integer('duration_ms')->nullable();
            ->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
