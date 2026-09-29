<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The uuid is sent to Moyasar as `given_id` on the charge request, so a
     * retried charge after a timeout can never take the money twice.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        DB::table('payments')->whereNull('uuid')->orderBy('id')->get()
            ->each(fn ($payment) => DB::table('payments')
                ->where('id', $payment->id)
                ->update(['uuid' => (string) Str::uuid()]));
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
