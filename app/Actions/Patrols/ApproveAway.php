<?php

namespace App\Actions\Patrols;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\AwayLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor confirms a long absence was fine (the appointment ran late,
 * the client sent them on an errand). It stops being flagged.
 */
final class ApproveAway
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Office staff's time away is their head's to approve (Heads); guards', Operations'.
     */
    public static function canApprove(AwayLog $away, User $actor): bool
    {
        $away->loadMissing('user.employeeProfile');

        if ($away->user_id === $actor->id) {
            return false;
        }

        return ($away->user->employeeProfile->is_office_staff ?? true)
            ? Heads::isHeadOf($actor, $away->user)
            : $actor->can(Ability::ManagePatrols->value) && $actor->canSeeEmployee($away->user);
    }

    public function handle(AwayLog $away, User $actor): AwayLog
    {
        if (! self::canApprove($away, $actor)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($away, $actor) {
            $away->forceFill(['approved_by' => $actor->id, 'approved_at' => CarbonImmutable::now()])->save();

            $this->audit->record(AuditArea::Attendance, 'away.approved', "Approved {$away->user->name}'s time away ({$away->reason->label()}, {$away->minutesAway(CarbonImmutable::now())} min)", $away, causer: $actor);

            return $away;
        });
    }
}
