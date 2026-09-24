<?php

namespace Modules\Payments\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTO\CardDetails;
use Modules\Payments\DTO\ChargeRequest;
use Modules\Payments\DTO\ChargeResult;
use Modules\Payments\Enums\PaymentRecordStatus;
use Modules\Payments\Exceptions\GatewayException;

/**
 * The Moyasar driver (plan §9.6): basic auth with the secret key, amounts in
 * halalas. Tested with Http::fake() only — never call the real API in tests.
 *
 * Transport errors (timeouts, 5xx) never map to "failed": the real payment
 * state is unknown then, so a GatewayException is thrown and the payment
 * stays `initiated` until a later verify/webhook reconciles it.
 */
class MoyasarGateway implements PaymentGateway
{
    public function charge(ChargeRequest $request): ChargeResult
    {
        $payload = [
            'amount' => $request->amount,
            'currency' => $request->currency,
            'description' => $request->description,
            'callback_url' => $request->callbackUrl,
            'source' => [
                'type' => 'token',
                'token' => $request->token,
            ],
            'metadata' => $request->metadata,
        ];

        if ($request->givenId !== null) {
            $payload['given_id'] = $request->givenId;
        }

        try {
            $response = Http::withBasicAuth($this->secretKey(), '')
                ->post($this->baseUrl().'/payments', $payload);
        } catch (ConnectionException $e) {
            // The charge may have gone through — do not mark it failed.
            throw GatewayException::chargeFailed($e->getMessage());
        }

        if ($response->serverError()) {
            throw GatewayException::chargeFailed('HTTP '.$response->status());
        }

        if ($response->clientError()) {
            // 4xx: the request was rejected before processing — no charge happened.
            return ChargeResult::failed(
                failureReason: $response->json('message') ?? 'Gateway error',
                raw: $response->json() ?? [],
            );
        }

        return $this->mapPayment($response->json());
    }

    public function fetch(string $gatewayPaymentId): ChargeResult
    {
        try {
            $response = Http::withBasicAuth($this->secretKey(), '')
                ->get($this->baseUrl().'/payments/'.$gatewayPaymentId);
        } catch (ConnectionException $e) {
            throw GatewayException::fetchFailed($gatewayPaymentId, $e->getMessage());
        }

        if ($response->failed()) {
            throw GatewayException::fetchFailed($gatewayPaymentId, 'HTTP '.$response->status());
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
     * Moyasar statuses: initiated/authorized are still in flight (authorized
     * is captured later), paid/captured mean the money is collected, failed
     * and voided mean no money was taken, refunded means it was returned.
     * An unknown status is treated as in flight (never as failed) so a
     * booking is never cancelled over a payload we do not understand.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapPayment(array $data): ChargeResult
    {
        $moyasarStatus = $data['status'] ?? null;

        $status = match ($moyasarStatus) {
            'paid', 'captured' => PaymentRecordStatus::Paid->value,
            'initiated', 'authorized' => PaymentRecordStatus::Initiated->value,
            'failed', 'voided' => PaymentRecordStatus::Failed->value,
            'refunded' => PaymentRecordStatus::Refunded->value,
            default => null,
        };

        if ($status === null) {
            Log::warning("MoyasarGateway: unknown payment status [{$moyasarStatus}]; treating as initiated.", [
                'payment_id' => $data['id'] ?? null,
            ]);
            $status = PaymentRecordStatus::Initiated->value;
        }

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
