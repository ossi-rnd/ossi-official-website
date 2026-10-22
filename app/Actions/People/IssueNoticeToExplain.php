<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Enums\NteOutcome;
use App\Models\NoticeToExplain;
use App\Models\User;
use App\Notifications\NoticeToExplainNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The first notice (roadmap 30): the specific acts, with the date; the rule they
 * break; the penalty it could lead to; and at least 5 calendar days to explain in
 * writing. Due process (King of Kings Transport v. Mamac) needs all four, so all
 * four are required. The employee is told by bell and email.
 */
final class IssueNoticeToExplain
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $issuer, User $employee, string $subject, CarbonImmutable $incidentDate, string $details, string $rule, NteOutcome $possiblePenalty, CarbonImmutable $explainBy): NoticeToExplain
    {
        if (! NoticeToExplain::canIssue($issuer, $employee)) {
            throw new AuthorizationException;
        }

        $today = CarbonImmutable::today();
        $errors = array_filter([
            'subject' => mb_strlen(trim($subject)) < 5 ? 'Name the offence (e.g. "Sleeping on post").' : null,
            'incidentDate' => $incidentDate->isAfter($today) ? 'The date can\'t be in the future.' : null,
            'details' => mb_strlen(trim($details)) < 20 ? 'Describe the specific acts: what, when, where, who saw it.' : null,
            'rule' => mb_strlen(trim($rule)) < 5 ? 'Name the company rule or policy it breaks.' : null,
            'possiblePenalty' => $possiblePenalty === NteOutcome::Cleared ? 'Choose the penalty it could lead to.' : null,
            'explainBy' => $explainBy->isBefore($today->addDays(NoticeToExplain::MIN_DAYS)) ? 'Give at least '.NoticeToExplain::MIN_DAYS.' calendar days to explain: '.$today->addDays(NoticeToExplain::MIN_DAYS)->format('M j').' or later.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($issuer, $employee, $subject, $incidentDate, $details, $rule, $possiblePenalty, $explainBy) {
            $notice = new NoticeToExplain;
            $notice->forceFill([
                'user_id' => $employee->id,
                'issued_by' => $issuer->id,
                'subject' => trim($subject),
                'incident_date' => $incidentDate->toDateString(),
                'details' => trim($details),
                'rule_violated' => trim($rule),
                'possible_penalty' => $possiblePenalty,
                'explain_by' => $explainBy->toDateString(),
            ])->save();

            $this->audit->record(AuditArea::People, 'nte.issued', "Notice to Explain to {$employee->name}: {$notice->subject}", $notice, [
                'possible_penalty' => $possiblePenalty->value,
                'explain_by' => $notice->explain_by->toDateString(),
            ], causer: $issuer);

            $employee->notify(new NoticeToExplainNotice($notice, NoticeToExplainNotice::ISSUED));

            return $notice;
        });
    }
}
