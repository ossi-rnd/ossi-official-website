<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof attached to a leave (a medical certificate, a birth certificate, a Solo
 * Parent ID, a protection order). Private: streamed only to the employee and
 * whoever decides the leave (LeaveRequest::documentsVisibleTo), every view audited.
 *
 * @property int $id
 * @property int $leave_request_id
 * @property string $path
 * @property string|null $name
 * @property string $mime
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read LeaveRequest $leaveRequest
 */
class LeaveDocument extends Model
{
    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
