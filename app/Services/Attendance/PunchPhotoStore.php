<?php

namespace App\Services\Attendance;

use App\Enums\PunchType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates a camera capture and saves it on the PRIVATE photo disk.
 *
 * The browser's claimed MIME type is never trusted: the bytes must decode to
 * a real JPEG or PNG. (Safari sometimes returns PNG when asked for JPEG, so
 * both are accepted as long as the bytes prove it.)
 */
final class PunchPhotoStore
{
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
    ];

    public function store(string $dataUrl, User $user, PunchType $type, CarbonImmutable $at): string
    {
        return $this->storeAs($dataUrl, sprintf('punches/%d/%s', $user->id, $at->toDateString()), $type->value);
    }

    /**
     * The same checks for any camera capture (patrol scans), saved under $directory.
     */
    public function storeAs(string $dataUrl, string $directory, string $prefix): string
    {
        if (preg_match('/^data:image\/(?:jpeg|jpg|png);base64,(?<data>[A-Za-z0-9+\/=]+)$/', trim($dataUrl), $matches) !== 1) {
            throw $this->invalid('The photo must be captured with the camera.');
        }

        $binary = base64_decode($matches['data'], strict: true);

        if ($binary === false || $binary === '') {
            throw $this->invalid('The photo could not be read. Please retake it.');
        }

        if (strlen($binary) > (int) config('attendance.photo_max_bytes')) {
            throw $this->invalid('The photo is too large. Please retake it.');
        }

        $info = @getimagesizefromstring($binary);
        $extension = $info === false ? null : (self::ALLOWED_TYPES[$info[2]] ?? null);

        if ($extension === null) {
            throw $this->invalid('The photo is not a valid image.');
        }

        // Random name: the path reveals nothing and can't be guessed.
        $path = sprintf('%s/%s-%s.%s', $directory, $prefix, Str::uuid(), $extension);

        Storage::disk($this->disk())->put($path, $binary, ['visibility' => 'private']);

        return $path;
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk())->delete($path);
    }

    public function disk(): string
    {
        return (string) config('attendance.photo_disk');
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['photo' => $message]);
    }
}
