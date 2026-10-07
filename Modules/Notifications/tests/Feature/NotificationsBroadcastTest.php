<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Modules\Clients\Notifications\ClientWelcomeNotification;
use Modules\Notifications\Events\NotificationReceived;
use Modules\Notifications\Notifications\AdminActivityNotification;
use Modules\Users\Models\User;
use Tests\TestCase;

class NotificationsBroadcastTest extends TestCase
{
    /*


    public function test_a_stored_notification_is_broadcast_to_the_admins_channel(): void
    {
        Event::fake([NotificationReceived::class]);

        $admin = $this->createAdmin();
        $admin->notify(new AdminActivityNotification(['type' => 'new_booking', 'booking_id' => 5]));

        Event::assertDispatched(
            NotificationReceived::class,
            fn (NotificationReceived $event) => $event->broadcastOn()->name === "private-admin.{$admin->id}"
                && $event->broadcastAs() === 'notification.received'
                && $event->notification['type'] === 'new_booking'
                && $event->notification['data']['booking_id'] === 5
                && $event->notification['id'] === $admin->notifications()->first()->id
        );
    }

    public function test_a_stored_notification_is_broadcast_to_the_clients_channel(): void
    {
        Event::fake([NotificationReceived::class]);

        $client = $this->createClient();
        $client->notify(new ClientWelcomeNotification);

        Event::assertDispatched(
            NotificationReceived::class,
            fn (NotificationReceived $event) => $event->broadcastOn()->name === "private-client.{$client->id}"
                && $event->notification['type'] === 'client_welcome'
        );
    }

    public function test_mirrored_admin_copies_are_broadcast_to_each_staff_channel(): void
    {
        Event::fake([NotificationReceived::class]);

        $admin = $this->createAdmin();
        $client = $this->createClient();

        $client->notify(new ClientWelcomeNotification); // mirrored to staff (view-clients)

        Event::assertDispatched(
            NotificationReceived::class,
            fn (NotificationReceived $event) => $event->broadcastOn()->name === "private-admin.{$admin->id}"
                && $event->notification['type'] === 'client_welcome'
                && $event->notification['data']['recipient']['id'] === $client->id
        );
    }

    public function test_a_mail_only_notification_is_not_broadcast(): void
    {
        Event::fake([NotificationReceived::class]);

        $admin = $this->createAdmin();
        $admin->notify(new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return new MailMessage;
            }
        });

        Event::assertNotDispatched(NotificationReceived::class);
    }

    /*
    |----------------------------------------------------------------------
    | POST /broadcasting/auth — private channel authorization
    |----------------------------------------------------------------------
    |
    | The default test driver is `null`, whose auth() answers 200 without
    | ever running the channel callbacks — useless for authorization
    | coverage. Switching to `reverb` (a Pusher-protocol broadcaster) makes
    | the auth endpoint actually invoke routes/channels.php while only
    | signing locally, so no network access happens.
    */

    protected function useReverbBroadcaster(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'test-key');
        Config::set('broadcasting.connections.reverb.secret', 'test-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'test-app');

        // Channels were registered on the boot-time `null` broadcaster;
        // re-run channels.php so the reverb broadcaster knows them too.
        require base_path('routes/channels.php');
    }

    public function test_an_admin_can_subscribe_to_his_own_channel(): void
    {
        $this->useReverbBroadcaster();
        $admin = $this->actingAsAdmin();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-admin.{$admin->id}",
            'socket_id' => '1234.5678',
        ])->assertOk();
    }

    public function test_an_admin_cannot_subscribe_to_another_users_channel(): void
    {
        $this->useReverbBroadcaster();
        $this->actingAsAdmin();
        $other = User::factory()->admin()->create();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-admin.{$other->id}",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_a_client_can_subscribe_to_his_own_channel_only(): void
    {
        $this->useReverbBroadcaster();
        $client = $this->actingAsClient();
        $other = $this->createClient();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-client.{$client->id}",
            'socket_id' => '1234.5678',
        ])->assertOk();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-client.{$other->id}",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_a_client_token_cannot_subscribe_to_an_admin_channel(): void
    {
        $this->useReverbBroadcaster();
        $client = $this->actingAsClient();
        $admin = $this->createAdmin();

        // Same numeric id space on purpose: the channel guard scoping
        // (not the id check alone) is what must reject this.
        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-admin.{$admin->id}",
            'socket_id' => '1234.5678',
        ])->assertForbidden();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-admin.{$client->id}",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_guests_get_401_on_channel_auth(): void
    {
        $this->useReverbBroadcaster();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-admin.1',
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }
}
