<?php

namespace Modules\Payments\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Exceptions\GatewayException;
use Modules\Payments\Gateways\MoyasarGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoyasarGatewayTest extends TestCase
{
    protected MoyasarGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.moyasar.secret_key', 'sk_test_123');
        config()->set('payments.moyasar.base_url', 'https://api.moyasar.com/v1');

        $this->gateway = new MoyasarGateway;
    }

    protected function chargeRequest(): ChargeRequest
    {
        return new ChargeRequest(
            amount: 190000,
            currency: 'SAR',
            token: 'tok_moyasar_123',
            description: 'Booking BK-2026-000001',
            callbackUrl: 'http://localhost:3001/bookings/payment-callback',
            metadata: ['booking_id' => 1],
        );
    }

    public function test_charge_sends_the_correct_payload_and_maps_paid(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response([
                'id' => 'pay_abc',
                'status' => 'paid',
                'source' => ['type' => 'token', 'brand' => 'Visa', 'last_four' => '4242'],
            ]),
        ]);

        $result = $this->gateway->charge($this->chargeRequest());

        $this->assertTrue($result->isPaid());
        $this->assertSame('pay_abc', $result->gatewayPaymentId);
        $this->assertSame('visa', $result->cardBrand);
        $this->assertSame('4242', $result->cardLastFour);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.moyasar.com/v1/payments'
                && $request->method() === 'POST'
                && $request['amount'] === 190000
                && $request['currency'] === 'SAR'
                && $request['callback_url'] === 'http://localhost:3001/bookings/payment-callback'
                && $request['source'] === ['type' => 'token', 'token' => 'tok_moyasar_123']
                && $request['metadata'] === ['booking_id' => 1]
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_123:'));
        });
    }

    public function test_charge_maps_initiated_with_the_transaction_url(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response([
                'id' => 'pay_3ds',
                'status' => 'initiated',
                'source' => ['type' => 'token', 'transaction_url' => 'https://api.moyasar.com/3ds/abc'],
            ]),
        ]);

        $result = $this->gateway->charge($this->chargeRequest());

        $this->assertSame(PaymentRecordStatus::Initiated->value, $result->status);
        $this->assertSame('https://api.moyasar.com/3ds/abc', $result->transactionUrl);
    }

    public function test_charge_maps_failed_with_the_reason(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response([
                'id' => 'pay_fail',
                'status' => 'failed',
                'source' => ['type' => 'token', 'message' => 'Declined by issuer'],
            ]),
        ]);

        $result = $this->gateway->charge($this->chargeRequest());

        $this->assertTrue($result->isFailed());
        $this->assertSame('Declined by issuer', $result->failureReason);
    }

    public function test_a_client_error_on_charge_maps_to_failed(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $result = $this->gateway->charge($this->chargeRequest());

        $this->assertTrue($result->isFailed());
        $this->assertSame('Unauthorized', $result->failureReason);
    }

    public function test_a_server_error_on_charge_throws_instead_of_failing(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        $this->expectException(GatewayException::class);

        $this->gateway->charge($this->chargeRequest());
    }

    public function test_a_connection_error_on_charge_throws_instead_of_failing(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments' => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->expectException(GatewayException::class);

        $this->gateway->charge($this->chargeRequest());
    }

    public function test_fetch_maps_the_latest_status(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/pay_abc' => Http::response([
                'id' => 'pay_abc',
                'status' => 'paid',
                'source' => ['type' => 'token', 'brand' => 'mada', 'last_four' => '9898'],
            ]),
        ]);

        $result = $this->gateway->fetch('pay_abc');

        $this->assertTrue($result->isPaid());
        $this->assertSame('pay_abc', $result->gatewayPaymentId);
        $this->assertSame('mada', $result->cardBrand);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.moyasar.com/v1/payments/pay_abc');
    }

    public function test_fetch_throws_on_a_server_error_so_the_payment_stays_initiated(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/pay_abc' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        try {
            $this->gateway->fetch('pay_abc');
            $this->fail('GatewayException was not thrown');
        } catch (GatewayException $e) {
            $this->assertStringContainsString('pay_abc', $e->getMessage());
        }
    }

    public function test_fetch_throws_on_a_connection_error(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/pay_abc' => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->expectException(GatewayException::class);

        $this->gateway->fetch('pay_abc');
    }

    #[DataProvider('moyasarStatusProvider')]
    public function test_fetch_maps_the_other_moyasar_statuses_explicitly(string $moyasarStatus, PaymentRecordStatus $expected): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/pay_abc' => Http::response([
                'id' => 'pay_abc',
                'status' => $moyasarStatus,
                'source' => ['type' => 'token'],
            ]),
        ]);

        $this->assertSame($expected->value, $this->gateway->fetch('pay_abc')->status);
    }

    public static function moyasarStatusProvider(): array
    {
        return [
            'authorized is still in flight' => ['authorized', PaymentRecordStatus::Initiated],
            'captured is paid' => ['captured', PaymentRecordStatus::Paid],
            'refunded' => ['refunded', PaymentRecordStatus::Refunded],
            'voided means no money taken' => ['voided', PaymentRecordStatus::Failed],
        ];
    }

    public function test_an_unknown_status_is_treated_as_initiated_never_failed(): void
    {
        Http::fake([
            'api.moyasar.com/v1/payments/pay_abc' => Http::response([
                'id' => 'pay_abc',
                'status' => 'some_future_status',
                'source' => ['type' => 'token'],
            ]),
        ]);

        $this->assertSame(
            PaymentRecordStatus::Initiated->value,
            $this->gateway->fetch('pay_abc')->status,
        );
    }

    public function test_token_details_maps_the_card(): void
    {
        Http::fake([
            'api.moyasar.com/v1/tokens/tok_1' => Http::response([
                'brand' => 'Mastercard',
                'last_four' => '5544',
                'month' => '11',
                'year' => '2029',
                'name' => 'AHMED ALI',
            ]),
        ]);

        $details = $this->gateway->tokenDetails('tok_1');

        $this->assertSame('mastercard', $details->brand);
        $this->assertSame('5544', $details->lastFour);
        $this->assertSame(11, $details->expMonth);
        $this->assertSame(2029, $details->expYear);
        $this->assertSame('AHMED ALI', $details->holderName);
    }

    public function test_the_driver_name_is_moyasar(): void
    {
        $this->assertSame('moyasar', $this->gateway->name());
    }
}
