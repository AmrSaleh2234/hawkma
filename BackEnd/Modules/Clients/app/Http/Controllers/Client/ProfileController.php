<?php

namespace Modules\Clients\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clients\Http\Requests\Client\UpdatePasswordRequest;
use Modules\Clients\Http\Requests\Client\UpdateProfileRequest;
use Modules\Clients\Http\Requests\Client\UploadAvatarRequest;
use Modules\Clients\Http\Resources\ClientResource;
use Modules\Clients\Services\ClientService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Services\AvatarService;

class ProfileController extends ApiController
{
    public function __construct(
        protected ClientService $clients,
        protected AvatarService $avatars,
    ) {}

    /**
     * CLI-PRF-01 GET /api/v1/client/profile
     */
    public function show(Request $request): JsonResponse
    {
        return $this->success(ClientResource::detailed($request->user('client')));
    }

    /**
     * CLI-PRF-02 PUT /api/v1/client/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $client = $this->clients->update(
            $request->user('client'),
            $request->safe()->only(['name', 'email', 'phone', 'company_name']),
        );

        return $this->success(
            ClientResource::detailed($client),
            __('core::messages.profile_updated'),
        );
    }

    /**
     * CLI-PRF-03 POST /api/v1/client/profile/avatar
     */
    public function storeAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $client = $request->user('client');

        $this->avatars->update($client, $request->file('avatar'));

        return $this->success(
            ClientResource::make($client->fresh()),
            __('core::messages.avatar_updated'),
        );
    }

    /**
     * CLI-PRF-04 DELETE /api/v1/client/profile/avatar
     */
    public function destroyAvatar(Request $request): JsonResponse
    {
        $this->avatars->delete($request->user('client'));

        return $this->noContent(__('core::messages.deleted'));
    }

    /**
     * CLI-PRF-05 PUT /api/v1/client/profile/password
     *
     * Deletes the other tokens, keeps the current one.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $client = $request->user('client');

        $client->forceFill(['password' => $request->string('password')->value()])->save();

        $client->tokens()
            ->where('id', '!=', $client->currentAccessToken()->id)
            ->delete();

        return $this->noContent(__('core::messages.password_changed'));
    }
}
