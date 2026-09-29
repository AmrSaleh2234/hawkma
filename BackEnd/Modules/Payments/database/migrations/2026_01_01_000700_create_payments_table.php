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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('gateway', 30);
            $table->string('gateway_payment_id', 191)->nullable()->index();
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('transaction_url', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->json('gateway_response')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
