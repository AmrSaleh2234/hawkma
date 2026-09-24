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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('consultant_id')->constrained('users');
            $table->foreignId('client_id')->constrained('clients');
            $table->string('title', 191);
            $table->text('summary')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->dateTime('client_notified_at')->nullable();
            $table->dateTime('first_downloaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
