<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultant financial fields: his own per-session price (halalas, used for
// payout calculation only — clients still pay packages/subscriptions), the
// bank details his payouts are transferred to, and an optional per-consultant
// platform fee override (null = use the global setting).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('consultation_price')->nullable()->after('consultant_stage');
            $table->decimal('platform_fee_percent', 5, 2)->nullable()->after('consultation_price');
            $table->string('iban', 34)->nullable()->after('platform_fee_percent');
            $table->string('bank_name', 150)->nullable()->after('iban');
            $table->string('bank_account_name', 150)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'consultation_price',
                'platform_fee_percent',
                'iban',
                'bank_name',
                'bank_account_name',
            ]);
        });
    }
};
