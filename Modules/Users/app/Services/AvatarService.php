<?php

namespace Modules\Users\Services;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Shared helper for the `avatar` media collection; used by Users,
 * Consultants and Clients.
 */
class AvatarService
{
    public function update(HasMedia $model, UploadedFile $file): void
    {
        $model->addMedia($file)->toMediaCollection('avatar');
    }

    public function delete(HasMedia $model): void
    {
        $model->clearMediaCollection('avatar');
    }
}
