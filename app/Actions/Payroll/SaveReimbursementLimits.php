<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Reimbursement;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sets the reimbursement limits in the app: how old a receipt can be,
 * the most a staff reimbursement can be, and the most a guard's deployment claim
 * can be. Company policy, not law. Every change is audited with the old and new.
 */
final class SaveReimbursementLimits
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, int $fileWithinDays, int $maxAmount, int $deploymentMaxAmount): void
    {
        if (! $actor->can(Ability::ManagePayroll->value)) {
            throw new AuthorizationException;
        }

        $errors = array_filter([
            'fileWithinDays' => $fileWithinDays < 7 || $fileWithinDays > 365 ? 'From 7 to 365 days.' : null,
            'maxAmount' => $maxAmount < 100 || $maxAmount > 1000000 ? 'From ₱100 to ₱1,000,000.' : null,
            'deploymentMaxAmount' => $deploymentMaxAmount < 100 || $deploymentMaxAmount > 1000000 ? 'From ₱100 to ₱1,000,000.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $old = Reimbursement::limits();
        $new = ['file_within_days' => $fileWithinDays, 'max_amount' => $maxAmount, 'deployment_max_amount' => $deploymentMaxAmount];

        if ($old === $new) {
            return;
        }

        $setting = Setting::query()->find(Reimbursement::SETTING) ?? new Setting;
        $setting->forceFill(['key' => Reimbursement::SETTING, 'value' => $new, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditArea::Payroll, 'reimbursement.limits_changed', "Reimbursement limits: receipts from the last {$fileWithinDays} days, up to ₱".number_format($maxAmount).' (deployment claims ₱'.number_format($deploymentMaxAmount).')', $setting, ['old' => $old, 'new' => $new], causer: $actor);
    }
}
