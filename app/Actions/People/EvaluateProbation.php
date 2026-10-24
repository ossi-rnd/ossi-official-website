<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\EmployeeProfile;
use App\Models\ProbationEvaluation;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use App\Support\ProbationRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The head's probation evaluation (owner, 2026-10-24: the head evaluates, HR approves).
 * Pass, or doesn't meet the standards made known when hired (Labor Code Art. 296), with the
 * reasons. HR is told to approve it. When HR evaluates, it counts as approved at once.
 * Only before the probation's last day has passed: after it, the law already made them Regular.
 */
final class EvaluateProbation
{
    public function __construct(
        private readonly DecideProbationEvaluation $decide,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, EmployeeProfile $profile, string $outcome, string $reason): ProbationEvaluation
    {
        if (! ProbationEvaluation::canEvaluate($actor, $profile)) {
            throw new AuthorizationException;
        }

        $reason = trim($reason);
        $endsOn = ProbationRules::endsOn($profile);
        $errors = array_filter([
            'evaluationOutcome' => ! in_array($outcome, [ProbationEvaluation::PASS, ProbationEvaluation::FAIL], true) ? 'Choose pass, or doesn\'t meet the standards.' : null,
            'evaluationReason' => match (true) {
                $outcome === ProbationEvaluation::FAIL && mb_strlen($reason) < 30 => 'Say which standards weren\'t met, and how (a few sentences). The employee receives this in writing.',
                mb_strlen($reason) < 10 => 'A short note on how they did.',
                mb_strlen($reason) > 2000 => 'Keep it under 2,000 characters.',
                default => null,
            },
            'evaluation' => match (true) {
                $endsOn === null => 'Their hire date is missing, so the probation\'s end is unknown. HR adds it first.',
                $endsOn->isBefore(CarbonImmutable::today()) => 'Probation ended on '.$endsOn->format('M j, Y').': under the law they are already Regular.',
                $profile->probationEvaluations()->whereIn('status', [ProbationEvaluation::PENDING, ProbationEvaluation::APPROVED])->exists() => 'An evaluation is already waiting for HR, or was approved.',
                default => null,
            },
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $evaluation = DB::transaction(function () use ($actor, $profile, $outcome, $reason, $endsOn) {
            $evaluation = new ProbationEvaluation;
            $evaluation->forceFill([
                'employee_profile_id' => $profile->id,
                'outcome' => $outcome,
                'reason' => $reason,
                'ends_on' => $endsOn?->toDateString(),
                'status' => ProbationEvaluation::PENDING,
                'evaluated_by' => $actor->id,
                'evaluated_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::People, 'probation.evaluated', "Probation evaluation for {$profile->name}: {$evaluation->outcomeLabel()}", $evaluation, ['outcome' => $outcome, 'reason' => $reason], causer: $actor);

            return $evaluation;
        });

        // HR evaluating: approved at once (they're the approver).
        if (ProbationEvaluation::canDecide($actor, $profile)) {
            return $this->decide->handle($actor, $evaluation, approve: true);
        }

        foreach (User::query()->notifiable(Ability::ManagePeople->roles())->get() as $hr) {
            UpdateNotice::tell($hr, $actor, "Approve {$profile->name}'s probation evaluation",
                "{$actor->name}: {$evaluation->outcomeLabel()}. Decide before ".$endsOn?->format('M j, Y').', the end of probation.',
                route('employees.show', $profile), 'user', email: true);
        }

        return $evaluation;
    }
}
