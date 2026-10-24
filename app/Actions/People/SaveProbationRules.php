<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ProbationRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR sets the probation length (never more than 6 months: Labor Code Art. 296), when the
 * head and HR are first told to evaluate, and how long a Trainee trains. Audited with the
 * old and new values. A shorter probation applies to everyone still on probation.
 */
final class SaveProbationRules
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, int $months, int $remindDaysBefore, int $trainingDays): void
    {
        if (! $actor->can(Ability::ManagePeople->value)) {
            throw new AuthorizationException;
        }

        $errors = array_filter([
            'probationMonths' => $months < 1 || $months > ProbationRules::MAX_MONTHS ? 'From 1 to 6 months: the law allows no more than 6.' : null,
            'remindDaysBefore' => $remindDaysBefore < 7 || $remindDaysBefore > 60 ? 'From 7 to 60 days.' : null,
            'trainingDays' => $trainingDays < 1 || $trainingDays > 180 ? 'From 1 to 180 days.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $old = ['months' => ProbationRules::months(), 'remind_days_before' => ProbationRules::remindDaysBefore(), 'training_days' => ProbationRules::trainingDays()];
        $new = ['months' => $months, 'remind_days_before' => $remindDaysBefore, 'training_days' => $trainingDays];

        if ($old === $new) {
            return;
        }

        $setting = Setting::query()->find(ProbationRules::SETTING) ?? new Setting;
        $setting->forceFill(['key' => ProbationRules::SETTING, 'value' => $new, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditArea::People, 'probation.rules_changed', "Probation rules: {$months} months, evaluation asked {$remindDaysBefore} days before, training {$trainingDays} days", $setting, ['old' => $old, 'new' => $new], causer: $actor);
    }
}
