<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freeze the package display data (id, slug, name_ar, name_en) onto the
     * records that were purchased: later updates or deletes of a package
     * must never change what an existing subscription or booking shows.
     */
    public function up(): void
    {
        Schema::table('client_subscriptions', function (Blueprint $table) {
            $table->json('package_snapshot')->nullable()->after('package_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->json('package_snapshot')->nullable()->after('package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('package_snapshot');
        });

        Schema::table('client_subscriptions', function (Blueprint $table) {
            $table->dropColumn('package_snapshot');
        });
    }
};
