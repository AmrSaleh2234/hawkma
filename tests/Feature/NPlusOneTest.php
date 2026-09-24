<?php

namespace Tests\Feature;

use Modules\AccessControl\Models\Role;
use Modules\Bookings\Models\Booking;
use Modules\Clients\Models\Client;
use Modules\Packages\Models\ClientSubscription;
use Modules\Packages\Models\Package;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentMethod;
use Modules\Reports\Models\Report;
use Modules\Users\Models\User;
use Tests\TestCase;

/**
 * Plan Phase 12: every index endpoint answers with the same number of
 * queries whether it lists 3 or 10 records.
 */
class NPlusOneTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | Admin indexes
    |----------------------------------------------------------------------
    */

    public function test_admin_users_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/users',
            fn (int $n) => User::factory()->count($n)->create(),
        );
    }

    public function test_admin_roles_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/roles',
            fn (int $n) => collect(range(1, $n))->each(fn () => Role::create([
                'name' => 'role-'.fake()->unique()->lexify('????????'),
                'guard_name' => 'admin',
            ])),
        );
    }

    public function test_admin_clients_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/clients',
            fn (int $n) => Client::factory()->count($n)->create(),
        );
    }

    public function test_admin_consultants_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/consultants',
            fn (int $n) => collect(range(1, $n))->each(fn () => $this->createConsultant()),
        );
    }

    public function test_admin_packages_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/packages',
            fn (int $n) => Package::factory()->count($n)->create(),
        );
    }

    public function test_admin_bookings_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/bookings',
            fn (int $n) => Booking::factory()->count($n)->create(),
        );
    }

    public function test_admin_payments_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/payments',
            fn (int $n) => Payment::factory()->count($n)->create(),
        );
    }

    public function test_admin_reports_index(): void
    {
        $this->actingAsAdmin();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/admin/reports',
            fn (int $n) => Report::factory()->count($n)->create(),
        );
    }

    /*
    |----------------------------------------------------------------------
    | Client indexes
    |----------------------------------------------------------------------
    */

    public function test_client_bookings_index(): void
    {
        $client = $this->actingAsClient();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/client/bookings',
            fn (int $n) => Booking::factory()->count($n)->create(['client_id' => $client->id]),
        );
    }

    public function test_client_reports_index(): void
    {
        $client = $this->actingAsClient();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/client/reports',
            fn (int $n) => Report::factory()->count($n)->create(['client_id' => $client->id]),
        );
    }

    public function test_client_payments_index(): void
    {
        $client = $this->actingAsClient();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/client/payments',
            fn (int $n) => Payment::factory()->count($n)->create(['client_id' => $client->id]),
        );
    }

    public function test_client_payment_methods_index(): void
    {
        $client = $this->actingAsClient();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/client/payment-methods',
            fn (int $n) => PaymentMethod::factory()->count($n)->create(['client_id' => $client->id]),
        );
    }

    public function test_client_locations_index(): void
    {
        $client = $this->actingAsClient();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/client/locations',
            fn (int $n) => $client->locations()->createMany(
                collect(range(1, $n))->map(fn ($i) => [
                    'name' => "Branch {$i}-".fake()->unique()->lexify('????'),
                    'city' => 'Riyadh',
                    'address' => 'King Fahd Rd',
                    'is_default' => false,
                ])->all(),
            ),
        );
    }

    public function test_client_subscriptions_index(): void
    {
        $client = $this->actingAsClient();

        $this->assertIndexQueryCountIsStable(
            '/api/v1/client/subscriptions',
            fn (int $n) => ClientSubscription::factory()->count($n)->create(['client_id' => $client->id]),
        );
    }

    /*
    |----------------------------------------------------------------------
    | Public indexes
    |----------------------------------------------------------------------
    */

    public function test_public_packages_index(): void
    {
        $this->assertIndexQueryCountIsStable(
            '/api/v1/public/packages',
            fn (int $n) => Package::factory()->count($n)->create(),
        );
    }

    public function test_public_consultants_index(): void
    {
        $this->assertIndexQueryCountIsStable(
            '/api/v1/public/consultants',
            fn (int $n) => collect(range(1, $n))->each(fn () => $this->createConsultant()),
        );
    }
}
