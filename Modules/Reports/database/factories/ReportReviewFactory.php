<?php

namespace Modules\Reports\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Reports\Enums\ReportApprovalStatus;
use Modules\Reports\Models\Report;
use Modules\Reports\Models\ReportReview;
use Modules\Users\Models\User;

/**
 * @extends Factory<ReportReview>
 */
class ReportReviewFactory extends Factory
{
    protected $model = ReportReview::class;

    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'status' => ReportApprovalStatus::PendingReview,
            'note' => null,
            'media_id' => null,
            'actor_id' => User::factory(),
        ];
    }
}
