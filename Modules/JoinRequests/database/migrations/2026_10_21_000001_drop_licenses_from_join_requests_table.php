<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// licenses moved from a free-text column to uploaded files (media collection).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('join_requests', function (Blueprint $table): void {
            $table->dropColumn('licenses');
        });
    }

    public function down(): void
    {
        Schema::table('join_requests', function (Blueprint $table): void {
            $table->string('licenses')->nullable()->after('bio');
        });
    }
};
