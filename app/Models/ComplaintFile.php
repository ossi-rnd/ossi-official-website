<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof attached to a complaint (a screenshot, a photo, a document). Confidential:
 * streamed only to the complainant and the complaint's handler
 * (ShowComplaintFileController), every handler view audited.
 *
 * @property int $id
 * @property int $complaint_id
 * @property string $path
 * @property string|null $name
 * @property string $mime
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read Complaint $complaint
 */
class ComplaintFile extends Model
{
    /**
     * @return BelongsTo<Complaint, $this>
     */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }
}
