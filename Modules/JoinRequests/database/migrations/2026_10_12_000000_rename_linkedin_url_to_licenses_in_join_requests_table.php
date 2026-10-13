<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('join_requests', function (Blueprint $table): void {
            $table->renameColumn('linkedin_url', 'licenses');
        });
    }

    public function down(): void
    {
        Schema::table('join_requests', function (Blueprint $table): void {
            $table->renameColumn('licenses', 'linkedin_url');
        });
    }
};
