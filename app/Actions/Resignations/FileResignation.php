<?php

namespace App\Actions\Resignations;

use App\Actions\Complaints\FileComplaint;
use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ComplaintCategory;
use App\Enums\ResignationReason;
use App\Models\Resignation;
use App\Models\User;
use App\Notifications\ResignationNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee resigns: the letter, the reason, and the last day they ask for.
 * Labor Code Art. 300: at least 30 days' written notice, unless it's for a
 * just cause; a shorter notice must ask HR to waive the rest. HR may also
 * enter a paper letter for the employee ($recordedBy). A just cause also
 * opens a complaint case about what happened (FileComplaint).
 */
final class FileResignation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FileComplaint $complaints,
    ) {}

    /**
     * $reasons: every one ticked on the checklist (or a single one). A just cause among
     * them (Art. 300(b)) means no notice is needed.
     *
     * $consentsToDeduction: written consent (Labor Code Art. 113) to deduct unpaid loans,
     * load and accountabilities from the final pay and cash bond. Without it, what's owed
     * is shown on the final-pay statement but not taken.
     *
     * $acknowledged: the employee ticked "I've read these" on the form; the lines they
     * read (Resignation::acknowledgementLines) are saved word for word, with the time.
     * They include the authorization to deduct what's owed, so it also sets the consent.
     *
     * @param  ResignationReason|array<ResignationReason>  $reasons
     */
    public function handle(User $employee, ResignationReason|array $reasons, ?string $reasonOther, string $letter, CarbonImmutable $requestedLastDay, bool $asksWaiver, ?User $recordedBy = null, bool $consentsToDeduction = false, bool $acknowledged = false, string $locale = 'en'): Resignation
    {
        $reasons = array_values(array_unique(is_array($reasons) ? $reasons : [$reasons], SORT_REGULAR));

        if ($reasons === []) {
            throw ValidationException::withMessages(['reasons' => 'Tick at least one reason.']);
        }

        // The main one: a just cause if any (it decides the notice), else the first ticked.
        $reason = collect($reasons)->first(fn (ResignationReason $r) => $r->skipsNotice()) ?? $reasons[0];

        $employee->loadMissing('employeeProfile');
        $forExecutive = Resignation::goesToExecutive($employee);

        // HR entering a paper letter: only for someone they handle (an HR member's goes to the President or Vice President).
        if ($recordedBy !== null && ! $recordedBy->can(($forExecutive ? Ability::HandleHrResignations : Ability::HandleResignations)->value)) {
            throw new AuthorizationException;
        }

        // The President or Vice President's leaving is decided outside the system (company decision, 2026-10-15).
        if ($employee->isPaidOutsidePayroll()) {
            throw ValidationException::withMessages(['reasons' => 'The President\'s or Vice President\'s resignation isn\'t filed here.']);
        }

        if ($employee->employeeProfile === null || ! $employee->employeeProfile->employment_status->isActive()) {
            throw ValidationException::withMessages(['reasons' => 'Only someone still working here can resign.']);
        }

        $hasOthers = in_array(ResignationReason::Others, $reasons, true);

        if ($hasOthers && blank($reasonOther)) {
            throw ValidationException::withMessages(['reasonOther' => 'Write the other reason.']);
        }

        $today = CarbonImmutable::today();

        if ($requestedLastDay->lt($today)) {
            throw ValidationException::withMessages(['lastDay' => 'The last day can\'t be in the past.']);
        }

        $notice = (int) $today->diffInDays($requestedLastDay);

        if (! $reason->skipsNotice() && $notice < Resignation::NOTICE_DAYS && ! $asksWaiver) {
            throw ValidationException::withMessages(['lastDay' => 'The law asks for at least '.Resignation::NOTICE_DAYS.' days\' notice (Labor Code Art. 300): a last day on or after '.$today->addDays(Resignation::NOTICE_DAYS)->format('M j, Y').'. For an earlier one, ask '.($forExecutive ? 'the President or Vice President' : 'HR').' to waive the rest.']);
        }

        return DB::transaction(function () use ($employee, $reason, $reasons, $hasOthers, $reasonOther, $letter, $requestedLastDay, $asksWaiver, $recordedBy, $forExecutive, $notice, $consentsToDeduction, $acknowledged, $locale) {
            if (Resignation::query()->where('user_id', $employee->id)->open()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reasons' => 'There is already a resignation in progress.']);
            }

            $resignation = new Resignation;
            $resignation->forceFill([
                'user_id' => $employee->id,
                'for_executive' => $forExecutive,
                'reason' => $reason,
                'reasons' => array_map(fn (ResignationReason $r) => $r->value, $reasons),
                'reason_other' => $hasOthers ? trim((string) $reasonOther) : null,
                'letter' => trim($letter),
                'acknowledgement' => $acknowledged ? Resignation::acknowledgementLines($reason->skipsNotice(), $forExecutive ? 'the President or Vice President' : 'HR', $locale) : null,
                'acknowledged_at' => $acknowledged ? now() : null,
                'requested_last_day' => $requestedLastDay->toDateString(),
                // A waiver only matters when the notice is short and there's no just cause.
                'asks_waiver' => $asksWaiver && ! $reason->skipsNotice() && $notice < Resignation::NOTICE_DAYS,
                'recorded_by' => $recordedBy?->id,
                // The acknowledgement includes the written authorization to deduct what's owed (Art. 113).
                'deduction_consent_at' => $consentsToDeduction || $acknowledged ? now() : null,
            ])->save();

            $this->audit->record(AuditArea::People, 'resignation.filed', "{$employee->name} resigned, last day asked ".$requestedLastDay->format('M j, Y'), $resignation, causer: $recordedBy ?? $employee);

            $handlers = User::query()->notifiable($resignation->handlerAbility()->roles())->whereKeyNot($employee->id)->get();
            Notification::send($handlers, new ResignationNotice($resignation->setRelation('user', $employee), ResignationNotice::FILED));

            // A just cause is an accusation against the company or someone acting for it: open a
            // complaint case about it, so it's looked into and closed with a written outcome, not
            // only answered with a last day. Same handler (HR, or the President or Vice President for HR staff).
            if ($reason->skipsNotice()) {
                $causes = collect($reasons)->filter(fn (ResignationReason $r) => $r->skipsNotice())->map(fn (ResignationReason $r) => $r->labelForOthers())->implode(', ');
                $complaint = $this->complaints->handle($employee, ComplaintCategory::Conduct, 'Just cause given for resigning: '.$causes, trim($letter), null, null, exemptFromLimit: true);
                $complaint->forceFill(['resignation_id' => $resignation->id])->save();
                $resignation->setRelation('complaint', $complaint);
            }

            return $resignation;
        });
    }
}
