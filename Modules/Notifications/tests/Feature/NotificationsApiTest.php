<?php

namespace Modules\Notifications\Tests\Feature;

use Carbon\Carbon;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Notifications\NewBookingNotification;
use Modules\Clients\Models\Client;
use Modules\Clients\Notifications\ClientWelcomeNotification;
use Modules\Notifications\Notifications\AdminActivityNotification;
use Modules\Users\Models\User;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | GET …/notifications — list
    |----------------------------------------------------------------------
    */

    public function test_an_admin_lists_his_notifications_paginated(): void
    {
        $admin = $this->actingAsAdmin();

        $this->notify($admin, 'new_booking', ['booking_id' => 1, 'reference' => 'BK-2026-000001']);
        Carbon::setTestNow(now()->addSecond()); // distinct created_at → deterministic order
        $this->notify($admin, 'report_ready', ['report_id' => 7, 'title' => 'Q3 report']);

        $response = $this->getJson('/api/v1/admin/notifications');

        $this->assertPaginated($response);
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('report_ready', $response->json('data.0.type')); // newest first
        $this->assertNull($response->json('data.0.read_at'));
        $this->assertSame(7, $response->json('data.0.data.report_id'));
    }

    public function test_a_client_lists_his_notifications_paginated(): void
    {
        $client = $this->actingAsClient();

        $client->notify(new ClientWelcomeNotification);

        $response = $this->getJson('/api/v1/client/notifications');

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('client_welcome', $response->json('data.0.type'));
    }

    public function test_notifications_are_strictly_personal(): void
    {
        $admin = $this->actingAsAdmin();
        $other = $this->createAdmin();

        $this->notify($other, 'new_booking', ['booking_id' => 1]);
        $this->notify($admin, 'report_ready', ['report_id' => 2]);

        $response = $this->getJson('/api/v1/admin/notifications');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('report_ready', $response->json('data.0.type'));
    }

    public function test_the_unread_filter_and_type_filter(): void
    {
        $admin = $this->actingAsAdmin();

        $this->notify($admin, 'new_booking', ['booking_id' => 1]);
        $this->notify($admin, 'report_ready', ['report_id' => 2]);

        $admin->notifications()->where('data->type', 'report_ready')->first()->markAsRead();

        $this->assertSame(1, $this->getJson('/api/v1/admin/notifications?unread=1')->json('meta.total'));
        $this->assertSame('new_booking', $this->getJson('/api/v1/admin/notifications?unread=1')->json('data.0.type'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/notifications?type=report_ready')->json('meta.total'));
        $this->assertSame(2, $this->getJson('/api/v1/admin/notifications')->json('meta.total'));
    }

    public function test_per_page_drives_the_meta_block(): void
    {
        $admin = $this->actingAsAdmin();

        foreach (range(1, 3) as $i) {
            $this->notify($admin, 'new_booking', ['booking_id' => $i]);
        }

        $response = $this->getJson('/api/v1/admin/notifications?per_page=2&page=2');

        $response->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_has_no_n_plus_1(): void
    {
        $admin = $this->actingAsAdmin();
        $seed = fn (int $count) => collect(range(1, $count))
            ->each(fn ($i) => $this->notify($admin, 'new_booking', ['booking_id' => $i]));

        $this->assertIndexQueryCountIsStable('/api/v1/admin/notifications', $seed);
    }

    /*
    |----------------------------------------------------------------------
    | GET …/notifications/unread-count
    |----------------------------------------------------------------------
    */

    public function test_unread_count(): void
    {
        $admin = $this->actingAsAdmin();

        $this->notify($admin, 'new_booking', ['booking_id' => 1]);
        $this->notify($admin, 'new_booking', ['booking_id' => 2]);
        $admin->notifications()->where('data->booking_id', 2)->first()->markAsRead();

        $response = $this->getJson('/api/v1/admin/notifications/unread-count');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.unread_count', 1);
    }

    /*
    |----------------------------------------------------------------------
    | PATCH …/notifications/{id}/read and POST …/read-all
    |----------------------------------------------------------------------
    */

    public function test_mark_read(): void
    {
        $admin = $this->actingAsAdmin();
        $this->notify($admin, 'new_booking', ['booking_id' => 1]);

        $notification = $admin->notifications()->first();

        $response = $this->patchJson("/api/v1/admin/notifications/{$notification->id}/read");

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_a_user_cannot_read_anothers_notification(): void
    {
        $this->actingAsAdmin();
        $other = $this->createAdmin();
        $this->notify($other, 'new_booking', ['booking_id' => 1]);

        $notification = $other->notifications()->first();

        $this->patchJson("/api/v1/admin/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertNull($notification->refresh()->read_at);
    }

    public function test_a_client_cannot_read_a_users_notification(): void
    {
        $this->actingAsClient();
        $admin = $this->createAdmin();
        $this->notify($admin, 'new_booking', ['booking_id' => 1]);

        $notification = $admin->notifications()->first();

        $this->patchJson("/api/v1/client/notifications/{$notification->id}/read")
            ->assertNotFound();
    }

    public function test_mark_all_read(): void
    {
        $admin = $this->actingAsAdmin();

        foreach (range(1, 3) as $i) {
            $this->notify($admin, 'new_booking', ['booking_id' => $i]);
        }

        $response = $this->postJson('/api/v1/admin/notifications/read-all');

        $this->assertApiSuccess($response)
            ->assertJsonPath('data.marked_count', 3);

        $this->assertSame(0, $admin->unreadNotifications()->count());
    }

    /*
    |----------------------------------------------------------------------
    | DELETE …/notifications/{id}
    |----------------------------------------------------------------------
    */

    public function test_delete(): void
    {
        $admin = $this->actingAsAdmin();
        $this->notify($admin, 'new_booking', ['booking_id' => 1]);

        $notification = $admin->notifications()->first();

        $this->deleteJson("/api/v1/admin/notifications/{$notification->id}")
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_cannot_delete_anothers_notification(): void
    {
        $this->actingAsAdmin();
        $other = $this->createAdmin();
        $this->notify($other, 'new_booking', ['booking_id' => 1]);

        $notification = $other->notifications()->first();

        $this->deleteJson("/api/v1/admin/notifications/{$notification->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    /*
    |----------------------------------------------------------------------
    | Authentication
    |----------------------------------------------------------------------
    */

    public function test_guests_get_401_on_both_portals(): void
    {
        $this->getJson('/api/v1/admin/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/client/notifications')->assertUnauthorized();
    }

    /*
    |----------------------------------------------------------------------
    | Admin activity mirror (the admin sees everything)
    |----------------------------------------------------------------------
    */

    public function test_a_client_notification_is_mirrored_to_staff_with_the_permission(): void
    {
        $admin = $this->createAdmin(); // full permissions
        $consultant = $this->createConsultant();
        $client = Client::factory()->create();

        // Client books with the consultant → the consultant is notified …
        $consultant->notify(new NewBookingNotification(Booking::factory()->create([
            'client_id' => $client->id,
            'consultant_id' => $consultant->id,
        ])));

        // … and staff holding view-bookings get a copy in their own bell.
        $mirror = $admin->notifications()->first();

        $this->assertNotNull($mirror);
        $this->assertSame('new_booking', $mirror->data['type']);
        $this->assertSame('user', $mirror->data['recipient']['type']);
        $this->assertSame($consultant->id, $mirror->data['recipient']['id']);
    }

    public function test_the_mirror_goes_only_to_staff_not_consultants(): void
    {
        $this->createAdmin();
        $otherConsultant = $this->createConsultant();
        $consultant = $this->createConsultant();

        $consultant->notify(new NewBookingNotification(Booking::factory()->create([
            'consultant_id' => $consultant->id,
        ])));

        // The other consultant has view-bookings too but is not staff.
        $this->assertSame(0, $otherConsultant->notifications()->count());
        $this->assertSame(1, $consultant->notifications()->count()); // his own only
    }

    public function test_staff_without_the_permission_get_no_mirror(): void
    {
        $staff = $this->createStaffWithPermissions(['view-payments']); // no view-clients
        $client = Client::factory()->create();

        $client->notify(new ClientWelcomeNotification);

        $this->assertSame(0, $staff->notifications()->count());
    }

    public function test_reset_password_is_never_mirrored(): void
    {
        $admin = $this->createAdmin();
        $other = $this->createAdmin();

        $other->sendPasswordResetNotification('fake-token');

        $this->assertSame(1, $other->notifications()->count()); // his own row
        $this->assertSame(0, $admin->notifications()->count()); // nothing mirrored
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    /**
     * Store a database-channel notification for the given user without
     * touching the mail channel.
     *
     * @param  array<string, mixed>  $data
     */
    protected function notify(User $user, string $type, array $data): void
    {
        $user->notify(new AdminActivityNotification($data + ['type' => $type]));
    }
}
