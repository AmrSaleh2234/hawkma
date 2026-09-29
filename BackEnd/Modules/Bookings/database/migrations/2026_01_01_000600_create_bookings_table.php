<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            // Set in the model created event: BK-{Y}-{id padded to 6}.
            $table->string('reference', 30)->nullable()->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('consultant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->foreignId('client_subscription_id')->nullable()->constrained('client_subscriptions')->nullOnDelete();
            $table->foreignId('client_location_id')->nullable()->constrained('client_locations')->nullOnDelete();
            $table->json('location_snapshot')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('status', 30)->index();
            $table->string('report_status', 20)->default('none')->index();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('SAR');
            $table->string('payment_status', 20);
            $table->string('refund_status', 20)->default('none');
            $table->dateTime('expires_at')->nullable();
            $table->string('meeting_provider', 30)->nullable();
            $table->string('meeting_status', 20)->default('none');
            $table->string('meeting_url', 255)->nullable();
            $table->string('meeting_event_id', 191)->nullable();
            $table->text('client_notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by_type', 20)->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['consultant_id', 'starts_at']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
