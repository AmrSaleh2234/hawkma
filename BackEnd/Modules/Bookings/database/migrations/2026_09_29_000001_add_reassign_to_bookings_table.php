<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consultant "can't attend" flag + reassignment audit trail.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('reassign_requested_at')->nullable()->after('client_notes');
            $table->string('reassign_reason', 500)->nullable()->after('reassign_requested_at');
            $table->dateTime('reassigned_at')->nullable()->after('reassign_reason');
            $table->foreignId('reassigned_by')->nullable()->after('reassigned_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reassigned_by');
            $table->dropColumn(['reassign_requested_at', 'reassign_reason', 'reassigned_at']);
        });
    }
};
