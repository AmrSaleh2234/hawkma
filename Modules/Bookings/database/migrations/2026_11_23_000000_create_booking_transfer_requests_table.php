<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultant handoff requests: a consultant asks to move one of his
 * upcoming bookings to another consultant; an admin approves or rejects.
 * Only one pending request per booking (enforced by the service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_consultant_id')->constrained('users');
            $table->foreignId('to_consultant_id')->constrained('users');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('reason');
            $table->string('status')->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['from_consultant_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_transfer_requests');
    }
};
