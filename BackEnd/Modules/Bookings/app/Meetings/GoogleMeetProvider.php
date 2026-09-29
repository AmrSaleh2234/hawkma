<?php

namespace Modules\Bookings\Meetings;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Illuminate\Support\Str;
use Modules\Bookings\Contracts\MeetingProvider;
use Modules\Bookings\DTO\MeetingResult;
use Modules\Bookings\Models\Booking;

/**
 * Creates a Google Calendar event with a Meet link via a service account
 * with domain-wide delegation (plan §9.7).
 */
class GoogleMeetProvider implements MeetingProvider
{
    public function create(Booking $booking): MeetingResult
    {
        $service = $this->calendarService();

        $event = new Event([
            'summary' => "GCMC Consultation {$booking->reference} - {$booking->client->company_name}",
            'description' => "Consultant: {$booking->consultant->name}\nPackage: {$booking->package->name_en}",
            'start' => ['dateTime' => $booking->starts_at->toRfc3339String(), 'timeZone' => 'Asia/Riyadh'],
            'end' => ['dateTime' => $booking->ends_at->toRfc3339String(), 'timeZone' => 'Asia/Riyadh'],
            'attendees' => [
                ['email' => $booking->client->email],
                ['email' => $booking->consultant->email],
            ],
            'conferenceData' => ['createRequest' => [
                'requestId' => (string) Str::uuid(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]],
        ]);

        $created = $service->events->insert(
            config('bookings.google.calendar_id', 'primary'),
            $event,
            ['conferenceDataVersion' => 1, 'sendUpdates' => 'all'],
        );

        return new MeetingResult($created->getId(), $created->getHangoutLink());
    }

    public function cancel(Booking $booking): void
    {
        if ($booking->meeting_event_id === null) {
            return;
        }

        $this->calendarService()->events->delete(
            config('bookings.google.calendar_id', 'primary'),
            $booking->meeting_event_id,
        );
    }

    public function name(): string
    {
        return 'google_meet';
    }

    protected function calendarService(): Calendar
    {
        $client = new Client;
        $client->setAuthConfig(config('bookings.google.credentials_path'));
        $client->setScopes([Calendar::CALENDAR]);
        $client->setSubject(config('bookings.google.impersonate'));

        return new Calendar($client);
    }
}
