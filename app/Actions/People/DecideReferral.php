<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\ReferralStatus;
use App\Models\EmployeeProfile;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ReferralNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR closes a referral: hired (linked to the hire's record, the bonus and its due date
 * frozen from the terms in force: hire date + days to stay) or not hired (with a note).
 * The referrer is told either way.
 */
final class DecideReferral
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** With the hire's record: hired. Without one: not hired, and $note says why. */
    public function handle(Referral $referral, ?EmployeeProfile $hire, ?string $note, User $actor): void
    {
        if (! $actor->can(Ability::ManageReferrals->value)) {
            throw new AuthorizationException;
        }

        $hire !== null ? $this->hired($referral, $hire, $actor) : $this->notHired($referral, (string) $note, $actor);
    }

    private function hired(Referral $referral, EmployeeProfile $hire, User $actor): void
    {

        $errors = array_filter([
            'hireId' => match (true) {
                ! $hire->employment_status->isActive() => "{$hire->name} no longer works here.",
                $hire->hire_date === null => "{$hire->name}'s record has no hire date yet. Add it on the record first.",
                $hire->user_id !== null && $hire->user_id === $referral->referrer_id => 'Nobody can refer themselves.',
                Referral::query()->where('employee_profile_id', $hire->id)->whereKeyNot($referral->id)->exists() => "{$hire->name} is already linked to another referral.",
                default => null,
            },
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $terms = Referral::terms();

        DB::transaction(function () use ($referral, $hire, $actor, $terms) {
            $locked = $this->lockOpen($referral);
            $dueOn = CarbonImmutable::parse($hire->hire_date)->addDays($terms['days_to_stay']);

            $locked->forceFill([
                'status' => ReferralStatus::Hired,
                'employee_profile_id' => $hire->id,
                'bonus_amount' => number_format($terms['bonus_amount'], 2, '.', ''),
                'bonus_due_on' => $dueOn->toDateString(),
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditArea::People, 'referral.hired', "{$locked->referrer->name}'s referral {$locked->applicant_name} was hired ({$hire->employee_number}); bonus ₱".number_format($terms['bonus_amount'], 2)." due {$dueOn->format('M j, Y')}", $locked, causer: $actor);

            $locked->referrer->notify(new ReferralNotice($locked->setRelation('hire', $hire), ReferralNotice::HIRED));
        });
    }

    private function notHired(Referral $referral, string $note, User $actor): void
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['decisionNote' => 'Say why, briefly: the referrer is told.']);
        }

        DB::transaction(function () use ($referral, $note, $actor) {
            $locked = $this->lockOpen($referral);

            $locked->forceFill([
                'status' => ReferralStatus::NotHired,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => trim($note),
            ])->save();

            $this->audit->record(AuditArea::People, 'referral.not_hired', "{$locked->referrer->name}'s referral {$locked->applicant_name} was not hired: {$note}", $locked, causer: $actor);

            $locked->referrer->notify(new ReferralNotice($locked, ReferralNotice::NOT_HIRED));
        });
    }

    private function lockOpen(Referral $referral): Referral
    {
        $locked = Referral::query()->with('referrer')->lockForUpdate()->findOrFail($referral->id);

        if ($locked->status !== ReferralStatus::Submitted) {
            throw ValidationException::withMessages(['referral' => 'This referral was already closed.']);
        }

        return $locked;
    }
}
