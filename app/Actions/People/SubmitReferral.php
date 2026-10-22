<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\Position;
use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ReferralNotice;
use App\Services\AuditLogger;
use App\Support\PhilippineFormats;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee refers someone for hiring: their name, mobile number and the job.
 * Someone already referred and still open can't be referred twice (first one counts).
 * HR is told.
 */
final class SubmitReferral
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $referrer, string $name, string $phone, ?Position $position, ?string $note): Referral
    {
        if (! Referral::canRefer($referrer)) {
            throw new AuthorizationException;
        }

        $name = PhilippineFormats::name($name);
        $mobile = PhilippineFormats::readableMobile($phone);

        $errors = array_filter([
            'applicantName' => preg_match(PhilippineFormats::NAME_PATTERN, $name) !== 1 ? PhilippineFormats::NAME_MESSAGE : null,
            'applicantPhone' => $mobile === null ? 'Enter their Philippine mobile number, e.g. 0917 123 4567.' : null,
            'position' => $position !== null && ! in_array($position, Position::offered(), true) ? 'Choose a job from the list.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($referrer, $name, $mobile, $position, $note) {
            if (Referral::query()->open()->where('applicant_phone', $mobile)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['applicantPhone' => 'Someone already referred this person. The first referral counts.']);
            }

            $referral = new Referral;
            $referral->forceFill([
                'referrer_id' => $referrer->id,
                'applicant_name' => $name,
                'applicant_phone' => $mobile,
                'position' => $position,
                'note' => filled($note) ? trim((string) $note) : null,
                'status' => ReferralStatus::Submitted,
            ])->save();

            $this->audit->record(AuditArea::People, 'referral.submitted', "{$referrer->name} referred {$name}".($position ? " for {$position->label()}" : ''), $referral, causer: $referrer);

            Notification::send(User::query()->notifiable(Ability::ManageReferrals->roles())->whereKeyNot($referrer->id)->get(), new ReferralNotice($referral->setRelation('referrer', $referrer), ReferralNotice::SUBMITTED));

            return $referral;
        });
    }
}
