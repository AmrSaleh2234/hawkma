<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// consultant_stage: 'active' (listed publicly) or 'training' (approved join
// request still in the training pipeline — hidden from the public site).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('consultant_stage', 20)->default('active')->index()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['consultant_stage']);
            $table->dropColumn('consultant_stage');
        });
    }
};
