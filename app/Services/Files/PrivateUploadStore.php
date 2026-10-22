<?php

namespace App\Services\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves an upload on a PRIVATE disk under a random name, after checking the
 * file's first bytes. Used for 201 documents.
 *
 * Components validate `mimes:` too (Laravel reads the content for that),
 * but an Action can be called from places that skip that validation, so the
 * bytes are checked here as well.
 */
final class PrivateUploadStore
{
    private const SIGNATURES = [
        'pdf' => '%PDF-',
        'png' => "\x89PNG\r\n\x1a\n",
        'jpg' => "\xFF\xD8\xFF",
    ];

    private const MIMES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
    ];

    /**
     * @param  list<'pdf'|'png'|'jpg'>  $allowed
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $disk, string $directory, string $prefix, array $allowed, string $field = 'document'): array
    {
        $head = (string) file_get_contents($file->getRealPath(), length: 8);
        $extension = null;

        foreach ($allowed as $type) {
            if (str_starts_with($head, self::SIGNATURES[$type])) {
                $extension = $type;
                break;
            }
        }

        if ($extension === null) {
            $names = implode(', ', array_map(fn (string $type) => strtoupper($type), $allowed));

            throw ValidationException::withMessages([$field => "Upload a {$names} file."]);
        }

        // Random name: the path reveals nothing and can't be guessed.
        $path = $file->storeAs($directory, sprintf('%s-%s.%s', $prefix, Str::uuid(), $extension), ['disk' => $disk, 'visibility' => 'private']);

        if ($path === false) {
            throw ValidationException::withMessages([$field => 'The file could not be saved. Please try again.']);
        }

        return [
            'path' => $path,
            // Only ever shown back to people; it never becomes part of a path.
            'name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => self::MIMES[$extension],
            'size' => (int) $file->getSize(),
        ];
    }

    public function delete(string $disk, ?string $path): void
    {
        if ($path !== null) {
            Storage::disk($disk)->delete($path);
        }
    }
}
