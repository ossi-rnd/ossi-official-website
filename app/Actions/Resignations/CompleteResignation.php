<?php

namespace App\Actions\Resignations;

use App\Enums\AuditArea;
use App\Enums\ResignationStatus;
use App\Models\Resignation;
use App\Notifications\ResignationNotice;
use App\Services\AuditLogger;

/**
 * Closes an accepted resignation once both are true: the person has left
 * (separated, the day after the last day) and every clearance item is
 * ticked. Called after each tick and after each separation, so whichever
 * comes last closes it. Does nothing otherwise.
 */
final class CompleteResignation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Resignation $resignation): Resignation
    {
        if ($resignation->status !== ResignationStatus::Accepted || $resignation->separated_at === null || ! $resignation->fullyCleared()) {
            return $resignation;
        }

        $resignation->forceFill(['status' => ResignationStatus::Completed, 'completed_at' => now()])->save();
        $this->audit->record(AuditArea::People, 'resignation.completed', "{$resignation->user->name}'s resignation and clearance are complete", $resignation);

        $resignation->user->notify(new ResignationNotice($resignation, ResignationNotice::COMPLETED));

        return $resignation;
    }
}
