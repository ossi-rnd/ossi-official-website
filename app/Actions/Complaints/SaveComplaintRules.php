<?php

namespace App\Actions\Complaints;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ComplaintRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR sets how many complaints one employee can file in 7 days (0 = no limit).
 * Audited with the old and new values.
 */
final class SaveComplaintRules
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, int $perWeek): void
    {
        if (! $actor->can(Ability::HandleComplaints->value)) {
            throw new AuthorizationException;
        }

        if ($perWeek < 0 || $perWeek > 10) {
            throw ValidationException::withMessages(['perWeek' => 'From 0 (no limit) to 10.']);
        }

        $old = ComplaintRules::perWeek();

        if ($old === $perWeek) {
            return;
        }

        $setting = Setting::query()->find(ComplaintRules::SETTING) ?? new Setting;
        $setting->forceFill(['key' => ComplaintRules::SETTING, 'value' => ['per_week' => $perWeek], 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditArea::Complaints, 'complaint.rules_changed', $perWeek === 0 ? 'Complaint rules: no weekly limit' : "Complaint rules: up to {$perWeek} per employee in 7 days", $setting, ['old' => ['per_week' => $old], 'new' => ['per_week' => $perWeek]], causer: $actor);
    }
}
