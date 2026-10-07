<?php

namespace Modules\Reviews\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Notifications\ReviewSubmittedNotification;
use Tests\TestCase;

class ReviewsApiTest extends TestCase
{
    public function test_client_submits_a_review_and_staff_are_notified(): void
    {
        Notification::fake();
        $client = $this->actingAsClient();
        $admin = $this->createAdmin();
        $response = $this->postJson('/api/v1/client/reviews', $this->payload());
        $this->assertApiSuccess($response, 201)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.rating', 5);
        $this->assertDatabaseHas('reviews', ['client_id' => $client->id, 'status' => 'pending']);
        Notification::assertSentTo($admin, fn (ReviewSubmittedNotification $notification) => $notification->review->id === (int) $response->json('data.id'));
    }

    public function test_review_requires_rating_between_one_and_five(): void
    {
        $this->actingAsClient();
        $this->postJson('/api/v1/client/reviews', ['name' => 'N', 'comment' => 'C'])->assertUnprocessable();
        $this->postJson('/api/v1/client/reviews', [...$this->payload(), 'rating' => 6])->assertUnprocessable();
        $this->postJson('/api/v1/client/reviews', [...$this->payload(), 'rating' => 0])->assertUnprocessable();
    }

    public function test_guest_cannot_submit_a_review(): void
    {
        $this->postJson('/api/v1/client/reviews', $this->payload())->assertUnauthorized();
    }

    public function test_public_endpoint_shows_only_approved_reviews(): void
    {
        $client = $this->actingAsClient();
        $pending = Review::factory()->for($client)->create(['status' => 'pending']);
        $rejected = Review::factory()->for($client)->create(['status' => 'rejected']);
        $approved = Review::factory()->for($client)->create(['status' => 'approved', 'published_at' => now()]);
        $response = $this->getJson('/api/v1/public/reviews');
        $this->assertApiSuccess($response)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->id)->assertJsonMissing(['status' => 'pending']);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($pending->id));
        $this->assertFalse($ids->contains($rejected->id));
    }

    public function test_admin_approval_publishes_the_review(): void
    {
        $admin = $this->actingAsAdmin();
        $client = $this->createClient();
        $review = Review::factory()->for($client)->create(['status' => 'pending']);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($review->refresh()->published_at);
        $this->assertSame($admin->id, $review->reviewed_by);
        $this->getJson('/api/v1/public/reviews')->assertOk()->assertJsonPath('data.0.id', $review->id);
    }

    public function test_admin_rejection_hides_the_review(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $review = Review::factory()->for($client)->create(['status' => 'pending']);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id.'/reject', ['reason' => 'Not appropriate.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertNull($review->refresh()->published_at);
        $this->getJson('/api/v1/public/reviews')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reviewed_review_cannot_be_reviewed_again(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $review = Review::factory()->for($client)->create(['status' => 'approved', 'published_at' => now()]);
        $this->patchJson('/api/v1/admin/reviews/'.$review->id.'/approve')->assertUnprocessable();
        $this->patchJson('/api/v1/admin/reviews/'.$review->id.'/reject', ['reason' => 'x'])->assertUnprocessable();
    }

    public function test_client_sees_only_own_reviews(): void
    {
        $client = $this->actingAsClient();
        $other = $this->createClient();
        Review::factory()->for($client)->create();
        Review::factory()->for($other)->create();
        $this->getJson('/api/v1/client/reviews')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_consultant_cannot_access_admin_reviews(): void
    {
        $this->actingAsConsultant();
        $this->getJson('/api/v1/admin/reviews')->assertForbidden();
    }

    private function payload(): array
    {
        return ['name' => 'Satisfied Client', 'rating' => 5, 'comment' => 'Great service, very professional.'];
    }
}
