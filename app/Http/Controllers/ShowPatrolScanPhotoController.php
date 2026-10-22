<?php

namespace App\Http\Controllers;

use App\Models\PatrolScan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The camera frame that read a checkpoint's QR. Never a public URL: each
 * view passes PatrolScanPolicy. Operations looks at it to spot a scan of a
 * photo (a phone's edge, screen glare, pixels) instead of the sticker.
 */
final class ShowPatrolScanPhotoController extends Controller
{
    public function __invoke(PatrolScan $scan): StreamedResponse
    {
        Gate::authorize('viewPhoto', $scan);

        $disk = Storage::disk((string) config('attendance.photo_disk'));
        abort_unless($disk->exists($scan->photo_path), 404);

        return $disk->response($scan->photo_path, null, [
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
