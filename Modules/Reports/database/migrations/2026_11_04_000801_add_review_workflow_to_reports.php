<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The report review workflow (reports are approved before clients see them):
// - reports.approval_status tracks the current state
//   (submitted → pending_review → approved|rejected|returned).
// - report_reviews keeps an immutable history entry per action, each holding
//   a copy of the file as it was at that step.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('pending_review')->index()->after('summary');
            $table->text('review_note')->nullable()->after('approval_status');
            $table->foreignId('reviewed_by')->nullable()->after('review_note')->constrained('users');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            // Points at the media row of the version the client downloads;
            // older versions stay in the same collection as history.
            $table->foreignId('current_media_id')->nullable()->after('reviewed_at')->constrained('media')->nullOnDelete();
        });

        Schema::create('report_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->text('note')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_reviews');

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex(['approval_status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['approval_status', 'review_note', 'reviewed_at']);
        });
    }
};
