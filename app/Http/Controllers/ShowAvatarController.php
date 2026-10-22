<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShowAvatarController extends Controller
{
    public function __invoke(User $user): StreamedResponse
    {
        Gate::authorize('viewAvatar', $user);

        $disk = Storage::disk((string) config('attendance.photo_disk'));
        abort_unless($user->avatar_path !== null && $disk->exists($user->avatar_path), 404);

        // The URL carries ?v=<hash of path>, so it changes whenever the avatar does.
        return $disk->response($user->avatar_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
