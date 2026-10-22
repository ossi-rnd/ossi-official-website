<?php

namespace App\Http\Controllers;

use App\Models\TimeLogPunch;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Punch selfies are never public URLs. Each view passes TimeLogPunchPolicy:
 * the employee themselves, or staff who supervise attendance.
 */
final class ShowPunchPhotoController extends Controller
{
    public function __invoke(TimeLogPunch $punch): StreamedResponse
    {
        Gate::authorize('viewPhoto', $punch);

        $disk = Storage::disk((string) config('attendance.photo_disk'));
        abort_unless($disk->exists($punch->photo_path), 404);

        return $disk->response($punch->photo_path, null, [
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
