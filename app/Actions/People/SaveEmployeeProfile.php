<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Models\EmployeeProfile;
use App\Models\PositionChange;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an HR employee record. Creating one is what allows that
 * person to register (see ClaimEmployeeAccount).
 */
final class SaveEmployeeProfile
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated fillable attributes
     */
    public function handle(?EmployeeProfile $profile, array $data, ?Shift $shift, User $actor): EmployeeProfile
    {
        return DB::transaction(function () use ($profile, $data, $shift, $actor) {
            $isNew = $profile === null;
            $profile ??= new EmployeeProfile;

            $before = $isNew ? [] : [...$profile->only(array_keys($data)), 'shift_id' => $profile->shift_id];
            $oldPosition = $profile->position;

            $profile->fill($data);
            $profile->shift()->associate($shift);
            $profile->save();

            // A position edited on the form goes in the history too (from today), so pay for
            // earlier cut-offs keeps the position they were paid at. Promotions use ChangePosition.
            if (! $isNew && $profile->position !== null && $profile->position !== $oldPosition) {
                $change = new PositionChange;
                $change->forceFill([
                    'employee_profile_id' => $profile->id,
                    'from_position' => $oldPosition,
                    'to_position' => $profile->position,
                    'kind' => PositionChange::EDIT,
                    'effective_on' => CarbonImmutable::today()->toDateString(),
                    'recorded_by' => $actor->id,
                    'applied_at' => CarbonImmutable::now(),
                ])->save();
            }

            $after = [...$profile->only(array_keys($data)), 'shift_id' => $profile->shift_id];
            $changes = AuditLogger::diff($before, $after, EmployeeProfile::SENSITIVE);

            if ($isNew || $changes['new'] !== []) {
                $this->audit->record(
                    AuditArea::People,
                    $isNew ? 'profile.created' : 'profile.updated',
                    ($isNew ? 'Added employee ' : 'Updated employee ').$profile->name,
                    $profile,
                    $changes,
                    causer: $actor,
                );
            }

            if (! $isNew) {
                $this->tellEmployee($profile, array_keys($changes['new']), $actor);
            }

            return $profile;
        });
    }

    /**
     * What the employee must know about an edit HR made: where they report, and their
     * employment status (e.g. now Regular). Contact details and the like aren't announced.
     *
     * @param  list<string>  $changed
     */
    private function tellEmployee(EmployeeProfile $profile, array $changed, User $actor): void
    {
        if (array_intersect($changed, ['client_id', 'post', 'shift_id']) !== [] && $profile->hasDeployment()) {
            $profile->loadMissing('shift');
            UpdateNotice::tellProfile($profile, $actor, 'Your deployment changed',
                $profile->assignmentLabel().($profile->shift !== null ? ' · '.$profile->shift->label() : '').'.', route('attendance'), 'map-pin', email: true);
        }

        if (in_array('employment_status', $changed, true) && $profile->employment_status->isActive()) {
            UpdateNotice::tellProfile($profile, $actor, "Your employment status: {$profile->employment_status->label()}",
                'HR updated your record. See it on My Profile.', route('profile'), 'user', email: true);
        }
    }
}
