<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR sets the referral terms in the app: the bonus, and the days the hire must stay
 * before it's paid. Company policy, not law. A referral already hired keeps the terms
 * it was hired under. Every change is audited with the old and new.
 */
final class SaveReferralTerms
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, int $bonusAmount, int $daysToStay): void
    {
        if (! $actor->can(Ability::ManageReferrals->value)) {
            throw new AuthorizationException;
        }

        $errors = array_filter([
            'bonusAmount' => $bonusAmount < 0 || $bonusAmount > 100000 ? 'From ₱0 to ₱100,000.' : null,
            'daysToStay' => $daysToStay < 0 || $daysToStay > 365 ? 'From 0 to 365 days.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $old = Referral::terms();
        $new = ['bonus_amount' => $bonusAmount, 'days_to_stay' => $daysToStay];

        if ($old === $new) {
            return;
        }

        $setting = Setting::query()->find(Referral::SETTING) ?? new Setting;
        $setting->forceFill(['key' => Referral::SETTING, 'value' => $new, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditArea::People, 'referral.terms_changed', 'Referral bonus: ₱'.number_format($bonusAmount).", after {$daysToStay} days", $setting, ['old' => $old, 'new' => $new], causer: $actor);
    }
}
