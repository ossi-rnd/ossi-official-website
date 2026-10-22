<?php

namespace App\Models;

use App\Enums\LeaveStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a guard's leave going up to HR: the Satellite Operation's or
 * Operation HQ's endorsement, or "not recommended" with a reason. Either way
 * it moves up: only HR decides. Nothing is fillable: EndorseLeaveRequest
 * sets every column.
 *
 * @property int $id
 * @property int $leave_request_id
 * @property LeaveStage $stage
 * @property int $endorsed_by
 * @property bool $recommends
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read LeaveRequest $leaveRequest
 * @property-read User $endorser
 */
class LeaveEndorsement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => LeaveStage::class,
            'recommends' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endorser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
    }
}
