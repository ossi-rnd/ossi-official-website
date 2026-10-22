<?php

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Avatars live on the same private disk as punch photos and are streamed by
 * ShowAvatarController, so no `storage:link` and no public bucket are needed.
 */
final class UpdateAvatar
{
    public function handle(User $user, ?UploadedFile $avatar): void
    {
        $disk = Storage::disk((string) config('attendance.photo_disk'));

        if ($user->avatar_path !== null) {
            $disk->delete($user->avatar_path);
        }

        $path = $avatar?->store('avatars/'.$user->id, ['disk' => (string) config('attendance.photo_disk')]);

        $user->forceFill(['avatar_path' => $path === false ? null : $path])->save();
    }
}
