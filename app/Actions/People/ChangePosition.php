<?php

namespace App\Actions\People;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\Position;
use App\Models\EmployeeProfile;
use App\Models\PositionChange;
use App\Models\User;
use App\Notifications\PositionChangeNotice;
use App\Queries\People\PositionRatesInForceQuery;
use App\Queries\People\RatesInForceQuery;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A promotion, or a lateral change of position (roadmap 22), recorded by HR.
 *
 * The date is a cut-off start (the 1st or the 16th): pay uses the position held
 * on each cut-off's start, so this says exactly which payslip changes. It can be
 * up to 90 days back (the cut-offs already paid are then suggested as corrections
 * on the next run) or up to a year ahead (the position switches on its day,
 * ApplyPositionChanges). One change waiting at a time.
 *
 * The employee and Accounting are told; Accounting especially when the person has
 * a personal rate, which would still win over the new position's rate.
 */
final class ChangePosition
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PositionRatesInForceQuery $positionRates,
        private readonly RatesInForceQuery $personalRates,
    ) {}

    public function handle(User $actor, EmployeeProfile $profile, Position $to, string $kind, CarbonImmutable $effectiveOn, string $reason): PositionChange
    {
        if (! $actor->can(Ability::ManagePeople->value)) {
            throw new AuthorizationException;
        }

        $today = CarbonImmutable::today();
        $current = PositionChange::positionsOn([$profile], $effectiveOn)[$profile->id] ?? null;

        $errors = array_filter([
            'kind' => ! in_array($kind, [PositionChange::PROMOTION, PositionChange::TRANSFER], true) ? 'A promotion or a change of position.' : null,
            'toPosition' => match (true) {
                ! in_array($to, Position::for((bool) $profile->is_office_staff), true) => 'Choose a position for '.($profile->is_office_staff ? 'office staff' : 'field staff').'.',
                $to === $current => 'That\'s already their position then.',
                default => null,
            },
            'effectiveOn' => match (true) {
                ! in_array($effectiveOn->day, [1, 16], true) => 'Use a cut-off start, the 1st or the 16th: pay changes from that payslip.',
                $effectiveOn->isBefore($today->subDays(90)) => 'At most 90 days back.',
                $effectiveOn->isAfter($today->addYear()) => 'At most a year ahead.',
                default => null,
            },
            'reason' => mb_strlen(trim($reason)) < 5 ? 'Say why (e.g. "Passed the shift-in-charge assessment").' : null,
        ]);

        if ($errors === [] && PositionChange::query()->where('employee_profile_id', $profile->id)->pending()->exists()) {
            $errors['effectiveOn'] = 'A change is already waiting for its day. Cancel it first.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $profile, $to, $kind, $effectiveOn, $reason, $current, $today) {
            $now = ! $effectiveOn->isAfter($today);

            $change = new PositionChange;
            $change->forceFill([
                'employee_profile_id' => $profile->id,
                'from_position' => $current,
                'to_position' => $to,
                'kind' => $kind,
                'effective_on' => $effectiveOn->toDateString(),
                'reason' => trim($reason),
                'recorded_by' => $actor->id,
                'applied_at' => $now ? CarbonImmutable::now() : null,
            ])->save();

            // Back-dated or today: the record takes it now. Ahead: on its day.
            if ($now) {
                $profile->forceFill(['position' => $to])->save();
            }

            $this->audit->record(AuditArea::People, "position.{$kind}", "{$change->kindLabel()} of {$profile->name}: ".($current?->label() ?? 'none')." → {$to->label()} from {$effectiveOn->format('M j, Y')}", $change, [
                'from' => $current?->value,
                'to' => $to->value,
                'effective_on' => $effectiveOn->toDateString(),
                'reason' => $change->reason,
            ], causer: $actor);

            $change->setRelation('profile', $profile);
            $profile->loadMissing('user');
            $profile->user?->notify(new PositionChangeNotice($change, PositionChangeNotice::EMPLOYEE));

            // Accounting: the new rate, or that a personal rate still wins.
            $hasPersonalRate = isset($this->personalRates->handle([$profile->id], $effectiveOn)[$profile->id]);
            $newRate = $this->positionRates->handle($effectiveOn)[$to->value] ?? null;
            Notification::send(
                User::query()->notifiable(Ability::ManagePayroll->roles())->get(),
                new PositionChangeNotice($change, PositionChangeNotice::ACCOUNTING, $newRate, $hasPersonalRate),
            );

            return $change;
        });
    }
}
