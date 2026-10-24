<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\EmploymentStatus;
use App\Support\Heads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The probation decision on one employee: their head evaluates (EvaluateProbation), HR
 * approves or sends it back (DecideProbationEvaluation). An approved pass makes them Regular
 * at once; an approved fail ends employment after the probation's last day (ApplyProbationEnds,
 * applied_at). Written only through those Actions, so no fillable list.
 *
 * @property int $id
 * @property int $employee_profile_id
 * @property string $outcome pass | fail
 * @property string $reason
 * @property CarbonImmutable $ends_on
 * @property string $status pending | approved | returned
 * @property int|null $evaluated_by
 * @property CarbonImmutable $evaluated_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read EmployeeProfile $profile
 * @property-read User|null $evaluator
 * @property-read User|null $decider
 */
class ProbationEvaluation extends Model
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ends_on' => 'date',
            'evaluated_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Who may evaluate: the person's head (App\Support\Heads, the Super Admin as backup), or
     * HR (who approves anyway, and evaluates someone not registered yet). Never themselves.
     */
    public static function canEvaluate(User $actor, EmployeeProfile $profile): bool
    {
        if ($profile->employment_status !== EmploymentStatus::Probationary || $profile->user_id === $actor->id) {
            return false;
        }

        return $actor->can(Ability::ManagePeople->value)
            || ($profile->user !== null && Heads::isHeadOf($actor, $profile->user));
    }

    /** HR approves (or sends back) a head's evaluation; never their own probation. */
    public static function canDecide(User $actor, EmployeeProfile $profile): bool
    {
        return $actor->can(Ability::ManagePeople->value) && $profile->user_id !== $actor->id;
    }

    public function passes(): bool
    {
        return $this->outcome === self::PASS;
    }

    public function outcomeLabel(): string
    {
        return $this->passes() ? 'Pass: make Regular' : 'Doesn\'t meet the standards';
    }
}
