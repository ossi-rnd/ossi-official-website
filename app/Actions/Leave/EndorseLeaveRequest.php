<?php

namespace App\Actions\Leave;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\LeaveStage;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\LeaveEndorsement;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\LeaveRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a guard's leave up to HR, who decides it (final):
 *  - at the Satellite step, the Satellite Operation of the guard's region
 *    endorses it to Operation HQ;
 *  - at the Operation HQ step, Operation HQ endorses it to HR. Operation HQ
 *    may also endorse at the Satellite step, so a slow or absent Satellite
 *    never holds up leave (it's recorded as Operation HQ's endorsement).
 *
 * "Not recommended" (with a reason) still moves it up: endorsers advise,
 * only HR approves or rejects. Each step tells the next one.
 */
final class EndorseLeaveRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The step this person would endorse it as, or null when they can't act on it now.
     */
    public static function endorsesAs(LeaveRequest $leave, User $endorser): ?LeaveStage
    {
        $leave->loadMissing('user.employeeProfile.client');

        if ($leave->status !== RequestStatus::Pending || $leave->user_id === $endorser->id || ! $endorser->can(Ability::EndorseGuardLeave->value)) {
            return null;
        }

        return match (true) {
            $leave->stage === LeaveStage::Satellite && $endorser->hasRole(Role::SatelliteOperations)
                && $endorser->operations_region !== null && $endorser->operations_region === LeaveRouting::regionOf($leave->user) => LeaveStage::Satellite,
            in_array($leave->stage, [LeaveStage::Satellite, LeaveStage::OperationsHq], true) && $endorser->hasRole(Role::OperationsHq) => LeaveStage::OperationsHq,
            default => null,
        };
    }

    public function handle(LeaveRequest $leave, User $endorser, bool $recommends, ?string $note): LeaveEndorsement
    {
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null;

        if (! $recommends && $note === null) {
            throw ValidationException::withMessages(['endorseNote' => 'Say why it isn\'t recommended, so HR can decide.']);
        }

        return DB::transaction(function () use ($leave, $endorser, $recommends, $note) {
            // Locked: two endorsers at once can't both move it.
            $locked = LeaveRequest::query()->with('user.employeeProfile.client')->lockForUpdate()->findOrFail($leave->id);
            $as = self::endorsesAs($locked, $endorser);

            if ($as === null) {
                throw ValidationException::withMessages(['endorse' => 'This leave isn\'t waiting for your endorsement.']);
            }

            $endorsement = new LeaveEndorsement;
            $endorsement->forceFill([
                'leave_request_id' => $locked->id,
                'stage' => $as,
                'endorsed_by' => $endorser->id,
                'recommends' => $recommends,
                'note' => $note,
            ])->save();

            $next = $as === LeaveStage::Satellite ? LeaveStage::OperationsHq : LeaveStage::Hr;
            $skipped = $as === LeaveStage::OperationsHq && $locked->stage === LeaveStage::Satellite;
            $locked->forceFill(['stage' => $next])->save();

            $this->audit->record(AuditArea::Leave, 'leave.endorsed', ($recommends ? 'Endorsed' : 'Did not recommend')." {$locked->user->name}'s leave ({$locked->dateRangeLabel()}) as {$as->label()}".($skipped ? ' (Satellite step skipped)' : '').", on to {$next->label()}", $locked, [
                'old' => ['stage' => ($skipped ? LeaveStage::Satellite : $as)->value],
                'new' => ['stage' => $next->value, 'recommends' => $recommends, 'note' => $note],
            ], causer: $endorser);

            LeaveRouting::notifyNext($locked->setRelation('endorsements', $locked->endorsements()->with('endorser')->get()));

            return $endorsement;
        });
    }
}
