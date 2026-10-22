<?php

namespace App\Actions\People;

use App\Enums\AuditArea;
use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** The referrer takes back a referral HR hasn't acted on yet. */
final class WithdrawReferral
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Referral $referral, User $referrer): void
    {
        if ($referral->referrer_id !== $referrer->id) {
            throw new AuthorizationException;
        }

        if ($referral->status !== ReferralStatus::Submitted) {
            throw ValidationException::withMessages(['referral' => 'HR already acted on it.']);
        }

        $referral->forceFill(['status' => ReferralStatus::Withdrawn])->save();
        $this->audit->record(AuditArea::People, 'referral.withdrawn', "{$referrer->name} withdrew the referral of {$referral->applicant_name}", $referral, causer: $referrer);
    }
}
