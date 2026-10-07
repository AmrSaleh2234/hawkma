<?php

namespace Modules\Users\Services;

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Modules\ActivityLogs\Enums\ActivityEvent;
use Modules\ActivityLogs\Support\ActivityLogger;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Users\Models\User;

class AdminAuthService
{
    /**
     * ADM-AUTH-01: verify credentials and issue a token.
     *
     * @return array{0: User, 1: NewAccessToken}
     */
    public function login(string $email, string $password, ?string $deviceName = null): array
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            ActivityLogger::api(ActivityEvent::LoginFailed, causer: $user, properties: [
                'guard' => 'admin',
                'email' => $email,
            ]);

            throw new BusinessException(
                ErrorCode::InvalidCredentials,
                errors: ['email' => [__('core::errors.INVALID_CREDENTIALS')]],
            );
        }

        if (! $user->is_active) {
            ActivityLogger::api(ActivityEvent::LoginFailed, causer: $user, properties: [
                'guard' => 'admin',
                'email' => $email,
                'reason' => 'account_disabled',
            ]);

            throw new BusinessException(ErrorCode::AccountDisabled, status: 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($deviceName ?: 'admin-dashboard');

        ActivityLogger::api(ActivityEvent::Login, causer: $user, properties: [
            'guard' => 'admin',
            'device_name' => $deviceName,
        ]);

        return [$user, $token];
    }

    /**
     * ADM-AUTH-02: revoke the current token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();

        ActivityLogger::api(ActivityEvent::Logout, causer: $user, properties: ['guard' => 'admin']);
    }
}
