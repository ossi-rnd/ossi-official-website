<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\EmploymentStatus;
use App\Models\EmployeeProfile;
use App\Models\ProbationEvaluation;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use App\Support\ProbationRules;
use Illuminate\Support\Facades\DB;

/**
 * Run daily, before the probation alerts. For everyone still Probationary after their
 * probation's last day (Labor Code Art. 296; owner, 2026-10-24):
 *
 *   a failed evaluation HR approved in time   Terminated: employment ends, account closes
 *   anything else                             Regular by law, automatically; a head's evaluation
 *                                             still waiting for HR is closed
 *
 * The employee and HR are told. Idempotent: once applied, they're no longer Probationary.
 */
final class ApplyProbationEnds
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return int how many probations were closed
     */
    public function handle(): int
    {
        $ids = EmployeeProfile::query()->probationOverdue()->pluck('id');
        $hr = User::query()->notifiable(Ability::ManagePeople->roles())->get();

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $hr) {
                $profile = EmployeeProfile::query()->with('user')->whereKey($id)->lockForUpdate()->first();
                $endsOn = $profile === null ? null : ProbationRules::endsOn($profile);

                if ($profile === null || $endsOn === null || $profile->employment_status !== EmploymentStatus::Probationary) {
                    return;
                }

                $failed = $profile->probationEvaluations()->where('status', ProbationEvaluation::APPROVED)->where('outcome', ProbationEvaluation::FAIL)->whereNull('applied_at')->first();
                $url = route('employees.show', $profile);

                if ($failed !== null) {
                    $profile->forceFill(['employment_status' => EmploymentStatus::Terminated])->save();
                    $failed->forceFill(['applied_at' => now()])->save();
                    $this->audit->record(AuditArea::People, 'probation.ended_employment', "{$profile->name}'s employment ended: probation not passed (last day {$endsOn->format('M j, Y')})", $profile, ['evaluation_id' => $failed->id]);

                    foreach ($hr as $person) {
                        UpdateNotice::tell($person, null, "Employment ended: {$profile->name}", "Probation not passed; the last day was {$endsOn->format('M j, Y')}. Start their final pay and clearance.", $url, 'user');
                    }

                    return;
                }

                $profile->forceFill(['employment_status' => EmploymentStatus::Regular])->save();
                $profile->probationEvaluations()->where('status', ProbationEvaluation::PENDING)
                    ->update(['status' => ProbationEvaluation::RETURNED, 'decision_note' => 'Probation ended before HR approved it: Regular by law.', 'decided_at' => now()]);
                $this->audit->record(AuditArea::People, 'probation.regularized', "{$profile->name} is now Regular: probation ended {$endsOn->format('M j, Y')} with no approved decision (Labor Code Art. 296)", $profile);

                UpdateNotice::tellProfile($profile, null, 'You are now a Regular employee', 'Your probation ended on '.$endsOn->format('F j, Y').'. See it on My Profile.', route('profile'), 'user', email: true);
                foreach ($hr as $person) {
                    UpdateNotice::tell($person, null, "Now Regular: {$profile->name}", "Probation ended {$endsOn->format('M j, Y')} with no approved decision, so they are Regular by law.", $url, 'user');
                }
            });
        }

        return $ids->count();
    }
}
