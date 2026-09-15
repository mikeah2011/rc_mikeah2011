<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint ) {
            ->integer('delivery_round')->default(1)->after('next_attempt_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint ) {
            ->dropColumn('delivery_round');
        });
    }
};
