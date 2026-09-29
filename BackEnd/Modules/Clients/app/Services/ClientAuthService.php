<?php

namespace Modules\Clients\Services;

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Modules\Clients\Models\Client;
use Modules\Clients\Notifications\ClientWelcomeNotification;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;

class ClientAuthService
{
    /**
     * CLI-AUTH-01: create the account and send the welcome notification.
     */
    public function register(array $data): Client
    {
        $client = Client::create($data);

        $client->notify(new ClientWelcomeNotification);

        return $client;
    }

    /**
     * CLI-AUTH-02: verify credentials and issue a token.
     *
     * @return array{0: Client, 1: NewAccessToken}
     */
    public function login(string $email, string $password, ?string $deviceName = null): array
    {
        $client = Client::query()->where('email', mb_strtolower($email))->first();

        if (! $client || ! Hash::check($password, $client->password)) {
            throw new BusinessException(
                ErrorCode::InvalidCredentials,
                errors: ['email' => [__('core::errors.INVALID_CREDENTIALS')]],
            );
        }

        if (! $client->is_active) {
            throw new BusinessException(ErrorCode::AccountDisabled, status: 403);
        }

        $client->forceFill(['last_login_at' => now()])->save();

        $token = $client->createToken($deviceName ?: 'client-dashboard');

        return [$client, $token];
    }

    /**
     * CLI-AUTH-03: revoke the current token.
     */
    public function logout(Client $client): void
    {
        $client->currentAccessToken()->delete();
    }
}
