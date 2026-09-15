<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'channel')) {
                $table->string('channel')->default('http')->after('status');
            }
        });

        Schema::table('notification_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_attempts', 'channel')) {
                $table->string('channel')->nullable()->after('attempt_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('notification_attempts', 'channel')) {
                $table->dropColumn('channel');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'channel')) {
                $table->dropColumn('channel');
            }
        });
    }
};
