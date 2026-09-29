<?php

namespace Modules\Payments\Tests\Unit;

use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Gateways\FakeGateway;
use Tests\TestCase;

class FakeGatewayTest extends TestCase
{
    protected FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
    }

    protected function chargeRequest(string $token): ChargeRequest
    {
        return new ChargeRequest(
            amount: 190000,
            currency: 'SAR',
            token: $token,
            description: 'Test charge',
            callbackUrl: 'http://localhost:3001/bookings/payment-callback',
            metadata: ['booking' => 'test'],
        );
    }

    public function test_tok_fake_success_is_paid(): void
    {
        $result = $this->gateway->charge($this->chargeRequest('tok_fake_success'));

        $this->assertSame(PaymentRecordStatus::Paid->value, $result->status);
        $this->assertTrue($result->isPaid());
        $this->assertNotNull($result->gatewayPaymentId);
        $this->assertSame('visa', $result->cardBrand);
        $this->assertSame('4242', $result->cardLastFour);
    }

    public function test_any_tok_fake_ok_token_is_paid(): void
    {
        $result = $this->gateway->charge($this->chargeRequest('tok_fake_ok_mada'));

        $this->assertTrue($result->isPaid());
    }

    public function test_tok_fake_3ds_is_initiated_with_a_transaction_url_then_fetch_is_paid(): void
    {
        $result = $this->gateway->charge($this->chargeRequest('tok_fake_3ds'));

        $this->assertSame(PaymentRecordStatus::Initiated->value, $result->status);
        $this->assertTrue($result->isInitiated());
        $this->assertNotNull($result->gatewayPaymentId);
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/fake-3ds/'.$result->gatewayPaymentId,
            $result->transactionUrl,
        );

        $fetched = $this->gateway->fetch($result->gatewayPaymentId);
        $this->assertTrue($fetched->isPaid());
        $this->assertSame($result->gatewayPaymentId, $fetched->gatewayPaymentId);
    }

    public function test_tok_fake_declined_fails_with_a_reason(): void
    {
        $result = $this->gateway->charge($this->chargeRequest('tok_fake_declined'));

        $this->assertSame(PaymentRecordStatus::Failed->value, $result->status);
        $this->assertTrue($result->isFailed());
        $this->assertSame('Card declined', $result->failureReason);
    }

    public function test_an_unknown_token_fails(): void
    {
        $result = $this->gateway->charge($this->chargeRequest('tok_whatever'));

        $this->assertTrue($result->isFailed());
        $this->assertSame('Unknown token', $result->failureReason);
    }

    public function test_token_details_returns_the_fake_card(): void
    {
        $details = $this->gateway->tokenDetails('tok_fake_success');

        $this->assertSame('visa', $details->brand);
        $this->assertSame('4242', $details->lastFour);
        $this->assertSame(12, $details->expMonth);
        $this->assertSame((int) now()->year + 2, $details->expYear);
        $this->assertSame('Test Card', $details->holderName);
    }

    public function test_the_driver_name_is_fake(): void
    {
        $this->assertSame('fake', $this->gateway->name());
    }
}
