<?php

namespace Modules\Payments\Services;

use Illuminate\Support\Facades\DB;
use Modules\Clients\Models\Client;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Models\PaymentMethod;

class PaymentMethodService
{
    public function __construct(protected PaymentGateway $gateway) {}

    /**
     * Save a card from a gateway token (plan CLI-PM-02). A duplicate token
     * returns the existing payment method.
     */
    public function storeFromToken(Client $client, string $token, bool $isDefault = false): PaymentMethod
    {
        $existing = $client->paymentMethods()
            ->where('gateway', $this->gateway->name())
            ->where('gateway_token', $token)
            ->first();

        if ($existing) {
            return $existing;
        }

        $details = $this->gateway->tokenDetails($token);

        return DB::transaction(function () use ($client, $token, $details, $isDefault): PaymentMethod {
            if ($isDefault) {
                $client->paymentMethods()->update(['is_default' => false]);
            }

            return $client->paymentMethods()->create([
                'gateway' => $this->gateway->name(),
                'gateway_token' => $token,
                'brand' => $details->brand,
                'last_four' => $details->lastFour,
                'exp_month' => $details->expMonth,
                'exp_year' => $details->expYear,
                'holder_name' => $details->holderName,
                'is_default' => $isDefault,
            ]);
        });
    }

    /**
     * Mark this method as the client's default; unset the others.
     */
    public function setDefault(PaymentMethod $paymentMethod): PaymentMethod
    {
        DB::transaction(function () use ($paymentMethod): void {
            $paymentMethod->client->paymentMethods()
                ->whereKeyNot($paymentMethod->id)
                ->update(['is_default' => false]);

            $paymentMethod->update(['is_default' => true]);
        });

        return $paymentMethod->refresh();
    }

    /**
     * Soft delete a saved card.
     */
    public function delete(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
    }
}
