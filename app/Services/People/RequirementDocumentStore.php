<?php

namespace App\Services\People;

use App\Enums\Requirement;
use App\Models\EmployeeProfile;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Http\UploadedFile;

/**
 * 201 scans on the PRIVATE documents disk: 201/{profile}/{requirement}-{uuid}.{ext}.
 * The byte checks live in PrivateUploadStore.
 */
final class RequirementDocumentStore
{
    public function __construct(private readonly PrivateUploadStore $uploads) {}

    /**
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, EmployeeProfile $profile, Requirement $requirement): array
    {
        return $this->uploads->store($file, $this->disk(), sprintf('201/%d', $profile->id), $requirement->value, ['pdf', 'png', 'jpg']);
    }

    public function delete(?string $path): void
    {
        $this->uploads->delete($this->disk(), $path);
    }

    public function disk(): string
    {
        return (string) config('hr.documents.disk');
    }
}
