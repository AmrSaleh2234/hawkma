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
        Schema::create('consultant_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday … 6 = Saturday (Carbon dayOfWeek)
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['consultant_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultant_availabilities');
    }
};
