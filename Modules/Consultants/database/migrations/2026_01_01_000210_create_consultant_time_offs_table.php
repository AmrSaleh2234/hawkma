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
        Schema::create('consultant_time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->date('date')->index();
            $table->time('start_time')->nullable(); // null together with end_time = the whole day
            $table->time('end_time')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultant_time_offs');
    }
};
