<?php

namespace Modules\Users\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Http\Requests\Admin\ForgotPasswordRequest;
use Modules\Users\Http\Requests\Admin\LoginRequest;
use Modules\Users\Http\Requests\Admin\ResetPasswordRequest;
use Modules\Users\Http\Resources\UserResource;
use Modules\Users\Models\User;
use Modules\Users\Services\AdminAuthService;

class AuthController extends ApiController
{
    /**
     * ADM-AUTH-01 POST /api/v1/admin/auth/login
     */
    public function login(LoginRequest $request, AdminAuthService $auth): JsonResponse
    {
        [$user, $token] = $auth->login(
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->input('device_name'),
        );

        return $this->success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user)->withPermissions(),
        ], __('core::messages.logged_in'));
    }

    /**
     * ADM-AUTH-02 POST /api/v1/admin/auth/logout
     */
    public function logout(Request $request, AdminAuthService $auth): JsonResponse
    {
        $auth->logout($request->user());

        return $this->noContent(__('core::messages.logged_out'));
    }

    /**
     * ADM-AUTH-03 GET /api/v1/admin/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(UserResource::make($request->user())->withPermissions());
    }

    /**
     * ADM-AUTH-04 POST /api/v1/admin/auth/forgot-password
     *
     * Always returns 200 with the same message, whether the email exists or not.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('users')->sendResetLink($request->only('email'));

        return $this->noContent(__('core::messages.password_reset_link_sent'));
    }

    /**
     * ADM-AUTH-05 POST /api/v1/admin/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill(['password' => $request->string('password')->value()])->save();

                // Delete all old tokens of the user.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return $this->noContent(__('core::messages.password_reset'));
    }
}
