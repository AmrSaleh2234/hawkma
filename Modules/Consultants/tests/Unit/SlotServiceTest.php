<?php

namespace Modules\Consultants\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Support\BookingsBusyTimeProvider;
use Modules\Consultants\Models\ConsultantAvailability;
use Modules\Consultants\Services\SlotService;
use Modules\Consultants\Support\NullBusyTimeProvider;
use Modules\Users\Models\User;
use Tests\TestCase;

/**
 * Plan §9.3 slot cases. Cases 3–5 use the real Bookings busy-time provider.
 */
class SlotServiceTest extends TestCase
{
    protected SlotService $slots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slots = new SlotService(new NullBusyTimeProvider);
    }

    /** Case 1: 10:00–12:00 → 10:00, 10:30, 11:00, 11:30. */
    public function test_case_1_simple_range(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');

        $times = $this->times($consultant, $this->sunday());

        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], $times);
    }

    /** Case 2: two ranges 10:00–11:00 and 14:00–15:00 → 4 slots. */
    public function test_case_2_two_ranges(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '11:00');
        $this->addSunday($consultant, '14:00', '15:00');

        $times = $this->times($consultant, $this->sunday());

        $this->assertSame(['10:00', '10:30', '14:00', '14:30'], $times);
    }

    /** Case 3: a booked 10:30 slot (status pending) is removed. */
    public function test_case_3_a_pending_booking_blocks_its_slot(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        Booking::factory()->create([
            'consultant_id' => $consultant->id,
            'starts_at' => $this->sunday()->setTime(10, 30),
            'ends_at' => $this->sunday()->setTime(11, 0),
        ]);

        $slots = new SlotService(new BookingsBusyTimeProvider);

        $this->assertSame(['10:00', '11:00', '11:30'], array_column($slots->getSlots($consultant, $this->sunday()), 'time'));
    }

    /** Case 4: pending_payment blocks only while its payment window is open. */
    public function test_case_4_pending_payment_blocks_until_it_expires(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        $slots = new SlotService(new BookingsBusyTimeProvider);

        // expires_at in the future → the slot is blocked.
        Booking::factory()->pendingPayment()->create([
            'consultant_id' => $consultant->id,
            'starts_at' => $this->sunday()->setTime(10, 30),
            'ends_at' => $this->sunday()->setTime(11, 0),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame(['10:00', '11:00', '11:30'], array_column($slots->getSlots($consultant, $this->sunday()), 'time'));

        // expires_at in the past → the slot is NOT blocked.
        Booking::query()->delete();
        Booking::factory()->pendingPayment()->create([
            'consultant_id' => $consultant->id,
            'starts_at' => $this->sunday()->setTime(10, 30),
            'ends_at' => $this->sunday()->setTime(11, 0),
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], array_column($slots->getSlots($consultant, $this->sunday()), 'time'));
    }

    /** Case 5: a cancelled booking does not block. */
    public function test_case_5_a_cancelled_booking_does_not_block(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        Booking::factory()->cancelled()->create([
            'consultant_id' => $consultant->id,
            'starts_at' => $this->sunday()->setTime(10, 30),
            'ends_at' => $this->sunday()->setTime(11, 0),
        ]);

        $slots = new SlotService(new BookingsBusyTimeProvider);

        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], array_column($slots->getSlots($consultant, $this->sunday()), 'time'));
    }

    /** Case 6: a whole-day time off → empty. */
    public function test_case_6_whole_day_time_off(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        $consultant->timeOffs()->create(['date' => $this->sunday()->toDateString()]);

        $this->assertSame([], $this->slots->getSlots($consultant, $this->sunday()));
    }

    /** Case 7: a time off 11:00–12:00 → 10:00, 10:30. */
    public function test_case_7_partial_time_off(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        $consultant->timeOffs()->create([
            'date' => $this->sunday()->toDateString(),
            'start_time' => '11:00',
            'end_time' => '12:00',
        ]);

        $this->assertSame(['10:00', '10:30'], $this->times($consultant, $this->sunday()));
    }

    /** Case 8: today at 10:15 with 60-minute notice → 11:30 only. */
    public function test_case_8_min_notice(): void
    {
        // "Today" is Sunday 2026-09-20; move the clock to 10:15.
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-20 10:15:00', 'Asia/Riyadh'));

        $consultant = $this->consultantWithSunday('10:00', '12:00');

        $this->assertSame(['11:30'], $this->times($consultant, CarbonImmutable::parse('2026-09-20', 'Asia/Riyadh')));
    }

    /** Case 9: a date in the past → empty; a date after the horizon → empty. */
    public function test_case_9_date_window(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');

        $past = CarbonImmutable::parse('2026-09-13', 'Asia/Riyadh'); // last Sunday
        $beyondHorizon = CarbonImmutable::now()->addDays(61);

        $this->assertSame([], $this->slots->getSlots($consultant, $past));
        $this->assertSame([], $this->slots->getSlots($consultant, $beyondHorizon));
    }

    /** Case 10: a day with no availability → empty. */
    public function test_case_10_no_availability(): void
    {
        $consultant = $this->createConsultant([], false);

        $this->assertSame([], $this->slots->getSlots($consultant, $this->sunday()));
    }

    /** Case 11: an inactive consultant → empty. */
    public function test_case_11_inactive_consultant(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        $consultant->forceFill(['is_active' => false])->save();

        $this->assertSame([], $this->slots->getSlots($consultant->fresh(), $this->sunday()));
    }

    /** Case 12: duration 60 and step 30, range 10:00–12:00 → 10:00, 10:30, 11:00. */
    public function test_case_12_longer_duration(): void
    {
        config()->set('bookings.duration_minutes', 60);

        $consultant = $this->consultantWithSunday('10:00', '12:00');

        $this->assertSame(['10:00', '10:30', '11:00'], $this->times($consultant, $this->sunday()));
    }

    public function test_non_consultant_user_gets_no_slots(): void
    {
        $admin = $this->createAdmin();

        $this->assertSame([], $this->slots->getSlots($admin, $this->sunday()));
    }

    public function test_slot_payload_shape(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');

        $slots = $this->slots->getSlots($consultant, $this->sunday());

        $this->assertSame('10:00', $slots[0]['time']);
        $this->assertSame('2026-09-27T10:00:00+03:00', $slots[0]['starts_at']);
        $this->assertSame('2026-09-27T10:30:00+03:00', $slots[0]['ends_at']);
    }

    public function test_is_slot_available(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');

        $this->assertTrue($this->slots->isSlotAvailable($consultant, CarbonImmutable::parse('2026-09-27 10:30', 'Asia/Riyadh')));
        $this->assertFalse($this->slots->isSlotAvailable($consultant, CarbonImmutable::parse('2026-09-27 12:00', 'Asia/Riyadh')));
    }

    public function test_get_available_dates_returns_only_days_with_slots(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');

        // Today (Sunday 09:00, 60 min notice → earliest 10:00) still has slots.
        $dates = $this->slots->getAvailableDates($consultant, CarbonImmutable::parse('2026-09-01', 'Asia/Riyadh'));

        $this->assertSame(['2026-09-20', '2026-09-27'], $dates);
    }

    public function test_get_available_dates_excludes_whole_day_time_offs(): void
    {
        $consultant = $this->consultantWithSunday('10:00', '12:00');
        $consultant->timeOffs()->create(['date' => '2026-09-27']);

        $dates = $this->slots->getAvailableDates($consultant, CarbonImmutable::parse('2026-09-01', 'Asia/Riyadh'));

        $this->assertSame(['2026-09-20'], $dates);
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    /** The next Sunday after "today" (2026-09-20 is a Sunday): 2026-09-27. */
    protected function sunday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-27', 'Asia/Riyadh');
    }

    protected function consultantWithSunday(string $start, string $end): User
    {
        $consultant = $this->createConsultant([], false);

        $this->addSunday($consultant, $start, $end);

        return $consultant;
    }

    protected function addSunday(User $consultant, string $start, string $end): ConsultantAvailability
    {
        return $consultant->availabilities()->create([
            'day_of_week' => 0,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function times(User $consultant, CarbonImmutable $date): array
    {
        return array_column($this->slots->getSlots($consultant, $date), 'time');
    }
}
