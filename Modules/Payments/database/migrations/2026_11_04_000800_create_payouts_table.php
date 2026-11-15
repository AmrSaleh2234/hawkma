<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('consultant_id')->constrained('users');
            // The consultant's per-session price, snapshotted at completion.
            $table->unsignedInteger('amount');
            // The platform fee percent + its halalas value, snapshotted too.
            $table->decimal('fee_percent', 5, 2);
            $table->unsignedInteger('fee_amount');
            // amount - fee_amount: what the consultant actually receives.
            $table->unsignedInteger('net_amount');
            $table->string('status', 20)->default('pending')->index();
            $table->string('driver', 30)->nullable();
            $table->string('transfer_reference', 150)->nullable();
            $table->text('release_note')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
