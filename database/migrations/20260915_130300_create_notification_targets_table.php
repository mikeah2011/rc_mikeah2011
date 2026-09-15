<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_targets', function (Blueprint $table) {
            $table->id();
            $table->string('notification_id');
            $table->string('channel')->index();
            $table->text('target')->nullable(); // e.g., url, email address, phone number or JSON
            $table->timestamps();

            $table->index('notification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_targets');
    }
};
