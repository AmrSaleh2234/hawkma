<?php

namespace Modules\Users\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Http\Requests\Admin\UpdatePasswordRequest;
use Modules\Users\Http\Requests\Admin\UpdateProfileRequest;
use Modules\Users\Http\Requests\Admin\UploadAvatarRequest;
use Modules\Users\Http\Resources\UserResource;
use Modules\Users\Services\AvatarService;

class ProfileController extends ApiController
{
    /**
     * ADM-PRF-01 GET /api/v1/admin/profile
     */
    public function show(Request $request): JsonResponse
    {
        return $this->success(UserResource::make($request->user())->withPermissions());
    }

    /**
     * ADM-PRF-02 PUT /api/v1/admin/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'email', 'phone']));

        if ($user->isConsultant()) {
            $user->fill($request->safe()->only(['title', 'specialization', 'bio']));
        }

        $user->save();

        return $this->success(
            UserResource::make($user)->withPermissions(),
            __('core::messages.profile_updated'),
        );
    }

    /**
     * ADM-PRF-03 POST /api/v1/admin/profile/avatar
     */
    public function storeAvatar(UploadAvatarRequest $request, AvatarService $avatars): JsonResponse
    {
        $user = $request->user();

        $avatars->update($user, $request->file('avatar'));

        return $this->success(
            UserResource::make($user->fresh())->withPermissions(),
            __('core::messages.avatar_updated'),
        );
    }

    /**
     * ADM-PRF-04 DELETE /api/v1/admin/profile/avatar
     */
    public function destroyAvatar(Request $request, AvatarService $avatars): JsonResponse
    {
        $avatars->delete($request->user());

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * ADM-PRF-05 PUT /api/v1/admin/profile/password
     *
     * Deletes the other tokens, keeps the current one.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->string('password')->value()])->save();

        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        return $this->noContent(__('core::messages.password_changed'));
    }
}
