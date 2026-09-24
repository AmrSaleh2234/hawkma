<?php

namespace Modules\Packages\Tests\Unit;

use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Packages\Enums\SubscriptionStatus;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Packages\Services\SubscriptionService;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    protected SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    /*
    |----------------------------------------------------------------------
    | quote() — the 4 cases from CLI-BKG-01 (plan §9.4 / §13)
    |----------------------------------------------------------------------
    */

    public function test_quote_requires_payment_when_the_client_has_no_subscription(): void
    {
        $client = $this->createClient();
        $package = Package::factory()->iron()->create();

        $quote = $this->service->quote($client, $package);

        $this->assertTrue($quote['requires_payment']);
        $this->assertSame(190000, $quote['amount']);
        $this->assertNull($quote['subscription']);
    }

    public function test_quote_is_free_with_an_active_subscription_with_remaining_quota(): void
    {
        $client = $this->createClient();
        $package = Package::factory()->iron()->create();
        $subscription = ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 1,
        ]);

        $quote = $this->service->quote($client, $package);

        $this->assertFalse($quote['requires_payment']);
        $this->assertSame(0, $quote['amount']);
        $this->assertTrue($quote['subscription']->is($subscription));
        $this->assertSame(1, $quote['remaining_before']);
    }

    public function test_quote_requires_payment_when_the_subscription_is_used_up(): void
    {
        $client = $this->createClient();
        $package = Package::factory()->iron()->create();
        ClientSubscription::factory()->forPackage($package)->usedUp()->create([
            'client_id' => $client->id,
        ]);

        $quote = $this->service->quote($client, $package);

        $this->assertTrue($quote['requires_payment']);
        $this->assertSame(190000, $quote['amount']);
        $this->assertNull($quote['subscription']);
    }

    public function test_quote_is_always_free_for_an_active_unlimited_gold_subscription(): void
    {
        $client = $this->createClient();
        $package = Package::factory()->gold()->create();
        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'consultations_used' => 999,
        ]);

        $quote = $this->service->quote($client, $package);

        $this->assertFalse($quote['requires_payment']);
        $this->assertSame(0, $quote['amount']);
        $this->assertNull($quote['remaining_before']);
    }

    public function test_quote_requires_payment_when_the_subscription_has_ended_but_is_still_marked_active(): void
    {
        $client = $this->createClient();
        $package = Package::factory()->iron()->create();
        ClientSubscription::factory()->forPackage($package)->create([
            'client_id' => $client->id,
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDay(), // ends_at <= now: the window has closed
        ]);

        $quote = $this->service->quote($client, $package);

        $this->assertTrue($quote['requires_payment']);
        $this->assertNull($quote['subscription']);
    }

    public function test_quote_ignores_subscriptions_of_other_packages(): void
    {
        $client = $this->createClient();
        $iron = Package::factory()->iron()->create();
        $silver = Package::factory()->silver()->create();
        ClientSubscription::factory()->forPackage($silver)->create(['client_id' => $client->id]);

        $quote = $this->service->quote($client, $iron);

        $this->assertTrue($quote['requires_payment']);
    }

    /*
    |----------------------------------------------------------------------
    | consume() — with and without a limit (plan §13)
    |----------------------------------------------------------------------
    */

    public function test_consume_increments_the_used_count(): void
    {
        $subscription = ClientSubscription::factory()->create([
            'consultations_limit' => 2,
            'consultations_used' => 0,
        ]);

        $this->service->consume($subscription);

        $this->assertSame(1, $subscription->consultations_used);
        $this->assertSame(1, $subscription->remaining());
    }

    public function test_consume_on_an_unlimited_subscription_increments_and_never_throws(): void
    {
        $subscription = ClientSubscription::factory()->unlimited()->create([
            'consultations_used' => 42,
        ]);

        $this->service->consume($subscription);

        $this->assertSame(43, $subscription->consultations_used);
        $this->assertNull($subscription->remaining());
        $this->assertTrue($subscription->hasRemaining());
    }

    public function test_consume_throws_a_business_exception_when_nothing_is_left(): void
    {
        $subscription = ClientSubscription::factory()->usedUp()->create();

        try {
            $this->service->consume($subscription);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::SubscriptionExhausted, $e->errorCode);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_consume_refuses_a_subscription_cancelled_after_the_quote(): void
    {
        $subscription = ClientSubscription::factory()->create([
            'consultations_limit' => 5,
            'consultations_used' => 1,
        ]);

        // The booking was quoted while active, then an admin refunded it.
        $this->service->cancel($subscription);

        try {
            $this->service->consume($subscription);
            $this->fail('BusinessException was not thrown');
        } catch (BusinessException $e) {
            $this->assertSame(ErrorCode::SubscriptionInactive, $e->errorCode);
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(1, $subscription->refresh()->consultations_used);
    }

    public function test_consume_refuses_a_subscription_past_its_end_even_if_still_marked_active(): void
    {
        $subscription = ClientSubscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subDay(),
            'consultations_limit' => 5,
            'consultations_used' => 1,
        ]);

        $this->expectException(BusinessException::class);

        $this->service->consume($subscription);
    }

    public function test_cancel_marks_an_active_subscription_cancelled_and_leaves_finished_ones(): void
    {
        $active = ClientSubscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $expired = ClientSubscription::factory()->create(['status' => SubscriptionStatus::Expired]);

        $this->service->cancel($active);
        $this->service->cancel($expired);

        $this->assertSame(SubscriptionStatus::Cancelled, $active->refresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $expired->refresh()->status);
    }

    /*
    |----------------------------------------------------------------------
    | release() — never below 0 (plan §13)
    |----------------------------------------------------------------------
    */

    public function test_release_decrements_the_used_count(): void
    {
        $subscription = ClientSubscription::factory()->create([
            'consultations_limit' => 2,
            'consultations_used' => 2,
        ]);

        $this->service->release($subscription);

        $this->assertSame(1, $subscription->consultations_used);
    }

    public function test_release_never_goes_below_zero(): void
    {
        $subscription = ClientSubscription::factory()->create([
            'consultations_used' => 0,
        ]);

        $this->service->release($subscription);

        $this->assertSame(0, $subscription->consultations_used);
    }

    /*
    |----------------------------------------------------------------------
    | subscriptions:expire (plan §9.4)
    |----------------------------------------------------------------------
    */

    public function test_expire_command_expires_active_subscriptions_past_their_end(): void
    {
        $expired = ClientSubscription::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subMinute(),
        ]);
        $stillActive = ClientSubscription::factory()->create([
            'ends_at' => now()->addDays(10),
        ]);
        $cancelled = ClientSubscription::factory()->cancelled()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subMinute(),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expired, $expired->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $stillActive->refresh()->status);
        $this->assertSame(SubscriptionStatus::Cancelled, $cancelled->refresh()->status);
    }

    // TODO Phase 9: activateFromPayment() test — needs the Payment model.
}
