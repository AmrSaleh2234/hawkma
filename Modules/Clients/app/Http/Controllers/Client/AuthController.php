<?php

namespace Modules\Clients\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Clients\Http\Requests\Client\ForgotPasswordRequest;
use Modules\Clients\Http\Requests\Client\LoginRequest;
use Modules\Clients\Http\Requests\Client\RegisterRequest;
use Modules\Clients\Http\Requests\Client\ResetPasswordRequest;
use Modules\Clients\Http\Resources\ClientResource;
use Modules\Clients\Models\Client;
use Modules\Clients\Services\ClientAuthService;
use Modules\Core\Http\Controllers\ApiController;

class AuthController extends ApiController
{
    public function __construct(protected ClientAuthService $auth) {}

    /**
     * CLI-AUTH-01 POST /api/v1/client/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $client = $this->auth->register($request->safe()->except('device_name'));

        $token = $client->createToken($request->input('device_name') ?: 'client-dashboard');

        return $this->created([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => ClientResource::make($client),
        ], __('core::messages.registered'));
    }

    /**
     * CLI-AUTH-02 POST /api/v1/client/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        [$client, $token] = $this->auth->login(
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->input('device_name'),
        );

        return $this->success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => ClientResource::make($client),
        ], __('core::messages.logged_in'));
    }

    /**
     * CLI-AUTH-03 POST /api/v1/client/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user('client'));

        return $this->noContent(__('core::messages.logged_out'));
    }

    /**
     * CLI-AUTH-04 GET /api/v1/client/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(ClientResource::detailed($request->user('client')));
    }

    /**
     * CLI-AUTH-05 POST /api/v1/client/auth/forgot-password
     *
     * Always returns 200 with the same message, whether the email exists or not.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('clients')->sendResetLink($request->only('email'));

        return $this->noContent(__('core::messages.password_reset_link_sent'));
    }

    /**
     * CLI-AUTH-06 POST /api/v1/client/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('clients')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Client $client) use ($request) {
                $client->forceFill(['password' => $request->string('password')->value()])->save();

                // Delete all old tokens of the client.
                $client->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return $this->noContent(__('core::messages.password_reset'));
    }
}
