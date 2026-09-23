<?php

namespace Modules\Payments\Gateways;

use Illuminate\Support\Facades\Http;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTO\CardDetails;
use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\DTO\ChargeResult;
use Modules\Payments\Enums\PaymentRecordStatus;

/**
 * The Moyasar driver (plan §9.6): basic auth with the secret key, amounts in
 * halalas. Tested with Http::fake() only — never call the real API in tests.
 */
class MoyasarGateway implements PaymentGateway
{
    public function charge(ChargeRequest $request): ChargeResult
    {
        $response = Http::withBasicAuth($this->secretKey(), '')
            ->post($this->baseUrl().'/payments', [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'description' => $request->description,
                'callback_url' => $request->callbackUrl,
                'source' => [
                    'type' => 'token',
                    'token' => $request->token,
                ],
                'metadata' => $request->metadata,
            ]);

        if ($response->failed()) {
            return ChargeResult::failed(
                failureReason: $response->json('message') ?? 'Gateway error',
                raw: $response->json() ?? [],
            );
        }

        return $this->mapPayment($response->json());
    }

    public function fetch(string $gatewayPaymentId): ChargeResult
    {
        $response = Http::withBasicAuth($this->secretKey(), '')
            ->get($this->baseUrl().'/payments/'.$gatewayPaymentId);

        if ($response->failed()) {
            return ChargeResult::failed(
                failureReason: $response->json('message') ?? 'Gateway error',
                gatewayPaymentId: $gatewayPaymentId,
                raw: $response->json() ?? [],
            );
        }

        return $this->mapPayment($response->json());
    }

    public function tokenDetails(string $token): CardDetails
    {
        $response = Http::withBasicAuth($this->secretKey(), '')
            ->get($this->baseUrl().'/tokens/'.$token);

        $response->throw();

        $data = $response->json();

        return new CardDetails(
            brand: strtolower((string) ($data['brand'] ?? '')),
            lastFour: (string) ($data['last_four'] ?? ''),
            expMonth: (int) ($data['month'] ?? 0),
            expYear: (int) ($data['year'] ?? 0),
            holderName: $data['name'] ?? null,
        );
    }

    public function name(): string
    {
        return 'moyasar';
    }

    /**
     * Map a Moyasar payment payload onto a ChargeResult.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapPayment(array $data): ChargeResult
    {
        $status = match ($data['status'] ?? null) {
            'paid' => PaymentRecordStatus::Paid->value,
            'initiated' => PaymentRecordStatus::Initiated->value,
            default => PaymentRecordStatus::Failed->value,
        };

        $source = $data['source'] ?? [];

        return new ChargeResult(
            status: $status,
            gatewayPaymentId: $data['id'] ?? null,
            transactionUrl: $source['transaction_url'] ?? null,
            failureReason: $status === PaymentRecordStatus::Failed->value
                ? ($source['message'] ?? $data['message'] ?? null)
                : null,
            cardBrand: isset($source['brand']) ? strtolower((string) $source['brand']) : null,
            cardLastFour: $source['last_four'] ?? null,
            raw: $data,
        );
    }

    protected function secretKey(): string
    {
        return (string) config('payments.moyasar.secret_key');
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('payments.moyasar.base_url'), '/');
    }
}
