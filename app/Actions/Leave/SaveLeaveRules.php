<?php

namespace App\Actions\Leave;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\LeaveRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR sets the leave rules in the app: from how many days sick leave needs a
 * medical certificate, and how long after it started sick or emergency leave can
 * be filed. Every change is audited with the old and new values.
 */
final class SaveLeaveRules
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, int $sickCertificateFromDays, int $fileAfterDays): void
    {
        if (! $actor->can(Ability::ManagePeople->value)) {
            throw new AuthorizationException;
        }

        $errors = array_filter([
            'sickCertificateFromDays' => $sickCertificateFromDays < 1 || $sickCertificateFromDays > 30 ? 'From 1 to 30 days.' : null,
            'fileAfterDays' => $fileAfterDays < 0 || $fileAfterDays > 30 ? 'From 0 to 30 days.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $old = LeaveRules::all();
        $new = ['sick_certificate_from_days' => $sickCertificateFromDays, 'file_after_days' => $fileAfterDays];

        if ($old === $new) {
            return;
        }

        $setting = Setting::query()->find(LeaveRules::SETTING) ?? new Setting;
        $setting->forceFill(['key' => LeaveRules::SETTING, 'value' => $new, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditArea::Leave, 'leave.rules_changed', "Leave rules: a medical certificate from {$sickCertificateFromDays} days of sick leave; sick or emergency leave filed up to {$fileAfterDays} days after", $setting, ['old' => $old, 'new' => $new], causer: $actor);
    }
}
