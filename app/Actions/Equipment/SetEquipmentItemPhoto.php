<?php

namespace App\Actions\Equipment;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The item's photo in the shop (Admin): replaced, or removed with null. Kept on the private photo
 * disk; ShowEquipmentPhotoController serves it to signed-in staff.
 */
final class SetEquipmentItemPhoto
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(EquipmentItem $item, ?UploadedFile $photo, User $actor): EquipmentItem
    {
        if (! $actor->can(Ability::ManageEquipmentItems->value)) {
            throw new AuthorizationException;
        }

        $disk = Storage::disk((string) config('attendance.photo_disk'));
        $old = $item->photo_path;
        $path = $photo?->store('equipment/'.$item->id, ['disk' => (string) config('attendance.photo_disk')]);

        $item->forceFill(['photo_path' => $path === false ? null : $path])->save();

        if ($old !== null && $old !== $item->photo_path) {
            $disk->delete($old);
        }

        $this->audit->record(AuditArea::Operations, $photo === null ? 'equipment_item.photo_removed' : 'equipment_item.photo_set', ($photo === null ? 'Removed the photo of' : 'Set the photo of')." \"{$item->name}\"", $item, causer: $actor);

        return $item;
    }
}
