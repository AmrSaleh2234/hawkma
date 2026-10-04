<?php

namespace Modules\SupportTickets\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Packages\Models\ClientSubscription;
use Modules\SupportTickets\Enums\TicketCategory;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Notifications\SupportTicketNotification;
use Tests\TestCase;

class SupportTicketsApiTest extends TestCase
{
    public function test_client_without_active_package_creates_service_for_admin(): void
    {
        $client = $this->actingAsClient();
        $response = $this->postJson('/api/v1/client/support-tickets', ['category' => 'technical', 'description' => 'The application is not loading.']);
        $this->assertApiSuccess($response, 201)->assertJsonPath('data.consultant', null)->assertJsonPath('data.messages.0.body', 'The application is not loading.');
        $this->assertDatabaseHas('support_tickets', ['client_id' => $client->id, 'consultant_id' => null, 'category' => 'technical']);
    }

    public function test_consultant_complaint_is_invisible_to_the_consultant(): void
    {
        $consultant = $this->createConsultant();
        $client = Client::factory()->create();
        $ticket = SupportTicket::query()->create(['client_id' => $client->id, 'consultant_id' => $consultant->id, 'category' => TicketCategory::ConsultantComplaint, 'status' => 'open', 'last_message_at' => now()]);
        $this->actingAsConsultant($consultant);
        $this->getJson('/api/v1/admin/support-tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_client_can_send_an_image_in_the_chat(): void
    {
        $client = $this->actingAsClient();
        $ticket = SupportTicket::query()->create(['client_id' => $client->id, 'category' => 'general_inquiry', 'status' => 'open', 'last_message_at' => now()]);
        $response = $this->postJson('/api/v1/client/support-tickets/'.$ticket->id.'/messages', ['image' => UploadedFile::fake()->image('question.jpg')]);
        $this->assertApiSuccess($response, 201)->assertJsonPath('data.image.name', 'question.jpg');
    }

    public function test_another_client_cannot_open_the_chat(): void
    {
        $ticket = SupportTicket::query()->create(['client_id' => Client::factory()->create()->id, 'category' => 'general_inquiry', 'status' => 'open', 'last_message_at' => now()]);
        $this->actingAsClient();
        $this->getJson('/api/v1/client/support-tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_active_package_routes_the_service_to_its_consultant(): void
    {
        Notification::fake();
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $subscription = ClientSubscription::factory()->create(['client_id' => $client->id]);
        Booking::factory()->create(['client_id' => $client->id, 'consultant_id' => $consultant->id, 'package_id' => $subscription->package_id, 'client_subscription_id' => $subscription->id]);

        $response = $this->postJson('/api/v1/client/support-tickets', ['category' => 'general_inquiry', 'description' => 'I need help.']);

        $this->assertApiSuccess($response, 201)->assertJsonPath('data.consultant.id', $consultant->id);
        Notification::assertSentTo($consultant, SupportTicketNotification::class);
    }

    public function test_consultant_complaint_never_notifies_the_consultant(): void
    {
        Notification::fake();
        $client = $this->actingAsClient();
        $consultant = $this->createConsultant();
        $subscription = ClientSubscription::factory()->create(['client_id' => $client->id]);
        Booking::factory()->create(['client_id' => $client->id, 'consultant_id' => $consultant->id, 'package_id' => $subscription->package_id, 'client_subscription_id' => $subscription->id]);

        $this->postJson('/api/v1/client/support-tickets', ['category' => 'consultant_complaint', 'description' => 'Private complaint.'])->assertCreated();

        Notification::assertNotSentTo($consultant, SupportTicketNotification::class);
    }
}
