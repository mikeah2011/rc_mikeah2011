<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('outbox')) {
            Schema::create('outbox', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('notification_id')->index();
                $table->uuid('target_id')->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('processed_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('outbox');
    }
};
