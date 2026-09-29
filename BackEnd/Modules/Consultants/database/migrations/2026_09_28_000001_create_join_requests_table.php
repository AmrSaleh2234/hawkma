<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('qualification');
            $table->string('experience')->nullable();
            $table->string('service_fields')->nullable();
            $table->string('licenses')->nullable();
            $table->string('phone');
            $table->string('email');
            $table->string('country_city')->nullable();
            $table->string('social_accounts')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_requests');
    }
};
