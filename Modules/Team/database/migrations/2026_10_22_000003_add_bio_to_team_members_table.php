<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Short bio shown under the member's name on the landing advisory-board card.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table): void {
            $table->text('bio')->nullable()->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropColumn('bio');
        });
    }
};
