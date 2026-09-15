<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('notification_id')->index();
            $table->integer('attempt_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('http_status')->nullable();
            $table->text('error')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
