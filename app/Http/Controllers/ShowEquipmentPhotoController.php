<?php

namespace App\Http\Controllers;

use App\Models\EquipmentItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An item's photo in the equipment shop. Any signed-in account may see the shop's photos, so the
 * route's `auth` is the check; the file is still private (no public URL outside the company).
 */
final class ShowEquipmentPhotoController extends Controller
{
    public function __invoke(EquipmentItem $item): StreamedResponse
    {
        $disk = Storage::disk((string) config('attendance.photo_disk'));
        abort_unless($item->photo_path !== null && $disk->exists($item->photo_path), 404);

        // The URL carries ?v=<hash of path>, so it changes whenever the photo does.
        return $disk->response($item->photo_path, null, ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
