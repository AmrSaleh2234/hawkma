<?php

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;

class MetaTest extends TestCase
{
    public function test_pub08_meta_returns_all_keys(): void
    {
        $response = $this->getJson('/api/v1/public/meta');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'booking_statuses',
                    'report_statuses',
                    'payment_statuses',
                    'days_of_week',
                    'booking' => [
                        'slot_minutes',
                        'duration_minutes',
                        'max_advance_days',
                        'min_notice_minutes',
                        'client_cancel_hours',
                    ],
                    'payment_gateway' => ['driver', 'publishable_key'],
                ],
            ]);

        $this->assertCount(7, $response->json('data.days_of_week'));
        $this->assertSame(30, $response->json('data.booking.slot_minutes'));
        $this->assertSame('fake', $response->json('data.payment_gateway.driver'));
    }

    public function test_pub08_days_of_week_are_localized(): void
    {
        $en = $this->getJson('/api/v1/public/meta', ['Accept-Language' => 'en']);
        $ar = $this->getJson('/api/v1/public/meta', ['Accept-Language' => 'ar']);

        $this->assertSame('Sunday', $en->json('data.days_of_week.0.name'));
        $this->assertSame('الأحد', $ar->json('data.days_of_week.0.name'));
    }
}
