<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Enums\EmploymentStatus;
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
 * HR approves a probation evaluation, or sends it back to the head with a note.
 *
 *   pass, approved   Regular at once (early regularization only helps the employee); they're told.
 *   fail, approved   only up to the probation's last day. The employee gets the written notice
 *                    now, with the reasons; employment ends after that day (ApplyProbationEnds).
 *   returned         the head is told why and may evaluate again.
 */
final class DecideProbationEvaluation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, ProbationEvaluation $evaluation, bool $approve, string $note = ''): ProbationEvaluation
    {
        $evaluation->loadMissing(['profile.user', 'evaluator']);
        $profile = $evaluation->profile;

        if (! ProbationEvaluation::canDecide($actor, $profile)) {
            throw new AuthorizationException;
        }

        $note = trim($note);
        $endsOn = ProbationRules::endsOn($profile);
        $errors = array_filter([
            'decision' => match (true) {
                $evaluation->status !== ProbationEvaluation::PENDING => 'It was already decided.',
                $profile->employment_status !== EmploymentStatus::Probationary => "{$profile->name} is no longer on probation.",
                $approve && ! $evaluation->passes() && ($endsOn === null || $endsOn->isBefore(CarbonImmutable::today())) => 'Probation already ended'.($endsOn ? ' on '.$endsOn->format('M j, Y') : '').': under the law they are Regular, so it can\'t be ended for failing probation.',
                default => null,
            },
            'decisionNote' => ! $approve && mb_strlen($note) < 10 ? 'Tell the head why it is sent back.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $evaluation, $approve, $note, $profile, $endsOn) {
            $evaluation->forceFill([
                'status' => $approve ? ProbationEvaluation::APPROVED : ProbationEvaluation::RETURNED,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => $note !== '' ? $note : null,
            ])->save();

            $this->audit->record(AuditArea::People, $approve ? 'probation.approved' : 'probation.returned', ($approve ? 'Approved' : 'Sent back')." {$profile->name}'s probation evaluation: {$evaluation->outcomeLabel()}", $evaluation, array_filter(['note' => $note]), causer: $actor);

            $url = route('employees.show', $profile);

            if (! $approve) {
                UpdateNotice::tell($evaluation->evaluator, $actor, "Probation evaluation sent back: {$profile->name}", "HR: {$note}", $url, 'user', email: true);

                return $evaluation;
            }

            if ($evaluation->passes()) {
                $profile->forceFill(['employment_status' => EmploymentStatus::Regular])->save();
                $this->audit->record(AuditArea::People, 'probation.regularized', "{$profile->name} is now Regular (probation passed)", $profile, causer: $actor);
                UpdateNotice::tellProfile($profile, $actor, 'You are now a Regular employee', 'You passed your probation. Congratulations! See it on My Profile.', route('profile'), 'user', email: true);
            } else {
                // The written notice, served before probation ends (Art. 296): the standards not met, and the last day.
                UpdateNotice::tellProfile($profile, $actor, 'Notice: your probation will not lead to regular employment',
                    'Your evaluation found that you did not meet the standards made known to you when you were hired: '.$evaluation->reason
                    .' Your employment ends after '.$endsOn?->format('F j, Y').', the last day of your probation. HR can tell you about your final pay and clearance.',
                    route('profile'), 'user', email: true);
            }

            UpdateNotice::tell($evaluation->evaluator, $actor, "Probation evaluation approved: {$profile->name}", $evaluation->outcomeLabel().'.', $url, 'user');

            return $evaluation;
        });
    }
}
