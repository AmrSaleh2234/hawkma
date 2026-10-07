<?php

namespace Modules\JoinRequests\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Modules\JoinRequests\Models\JoinRequest;
use Modules\JoinRequests\Notifications\JoinRequestRejectedNotification;
use Modules\JoinRequests\Notifications\JoinRequestSubmittedNotification;
use Modules\Users\Models\User;
use Modules\Users\Notifications\StaffAccountCreatedNotification;
use Tests\TestCase;

class JoinRequestsApiTest extends TestCase
{
    public function test_expert_submits_a_join_request_with_required_pdf_cv(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $response = $this->postJson('/api/v1/public/join-requests', $this->payload());
        $this->assertApiSuccess($response, 201)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.cv.mime_type', 'application/pdf');
        Notification::assertSentTo($admin, fn (JoinRequestSubmittedNotification $notification) => $notification->joinRequest->email === 'expert@example.com');
    }

    public function test_cv_is_required_and_must_be_pdf(): void
    {
        $payload = $this->payload();
        unset($payload['cv']);
        $this->postJson('/api/v1/public/join-requests', $payload)->assertUnprocessable();
        $payload['cv'] = UploadedFile::fake()->image('cv.jpg');
        $this->postJson('/api/v1/public/join-requests', $payload)->assertUnprocessable();
    }

    public function test_pending_email_cannot_submit_twice(): void
    {
        $this->postJson('/api/v1/public/join-requests', $this->payload())->assertCreated();
        $this->postJson('/api/v1/public/join-requests', $this->payload())->assertUnprocessable();
    }

    public function test_approval_creates_consultant_and_sends_set_password_email(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdmin();
        $joinRequest = JoinRequest::query()->create($this->record());
        $response = $this->patchJson('/api/v1/admin/join-requests/'.$joinRequest->id.'/approve');
        $this->assertApiSuccess($response)->assertJsonPath('data.status', 'approved');
        $consultant = User::query()->where('email', $joinRequest->email)->sole();
        $this->assertTrue($consultant->isConsultant());
        $this->assertTrue($consultant->hasRole('consultant'));
        Notification::assertSentTo($consultant, StaffAccountCreatedNotification::class);
    }

    public function test_rejection_stores_reason_and_sends_email(): void
    {
        Notification::fake();
        $this->actingAsAdmin();
        $joinRequest = JoinRequest::query()->create($this->record());
        $this->patchJson('/api/v1/admin/join-requests/'.$joinRequest->id.'/reject', ['reason' => 'Experience requirements were not met.'])->assertOk();
        $this->assertSame('rejected', $joinRequest->refresh()->status->value);
        Notification::assertSentOnDemand(JoinRequestRejectedNotification::class);
    }

    public function test_consultant_cannot_access_admin_join_requests(): void
    {
        $this->actingAsConsultant();
        $this->getJson('/api/v1/admin/join-requests')->assertForbidden();
    }

    private function payload(): array
    {
        return $this->record() + ['cv' => UploadedFile::fake()->createWithContent('cv.pdf', '%PDF-1.4 CV')];
    }

    private function record(): array
    {
        return ['name' => 'Expert Person', 'email' => 'expert@example.com', 'phone' => '+966500000000', 'specialization' => 'Governance', 'bio' => 'Ten years of experience.', 'linkedin_url' => 'https://linkedin.com/in/expert', 'status' => 'pending'];
    }
}
