<?php

namespace App\Actions\Resignations;

use App\Enums\AuditArea;
use App\Enums\ResignationStatus;
use App\Enums\Role;
use App\Models\Resignation;
use App\Models\User;
use App\Notifications\ResignationNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * HR (or the President or Vice President, for HR staff) accepts a resignation and fixes the
 * last day: the one asked for, or another agreed with the employee. A last day
 * under 30 days' notice without a just cause means the notice is waived.
 * The employee is told, and for a guard Operations too, to cover the post.
 *
 * There is no "reject": a resignation is the employee's right (Art. 300);
 * the only thing to settle is the date.
 */
final class AcceptResignation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Resignation $resignation, User $actor, CarbonImmutable $lastDay, ?string $note): Resignation
    {
        if (! $actor->can($resignation->handlerAbility()->value)) {
            throw new AuthorizationException;
        }

        if ($resignation->user_id === $actor->id) {
            throw ValidationException::withMessages(['lastDay' => 'Someone else accepts your own resignation.']);
        }

        if ($lastDay->lt($resignation->created_at->startOfDay())) {
            throw ValidationException::withMessages(['lastDay' => 'The last day can\'t be before the resignation was filed.']);
        }

        return DB::transaction(function () use ($resignation, $actor, $lastDay, $note) {
            $locked = Resignation::query()->with('user.employeeProfile.client')->lockForUpdate()->findOrFail($resignation->id);

            if ($locked->status !== ResignationStatus::Pending) {
                throw ValidationException::withMessages(['lastDay' => 'This resignation has been handled already.']);
            }

            $locked->forceFill([
                'status' => ResignationStatus::Accepted,
                'last_day' => $lastDay->toDateString(),
                'notice_waived' => ! $locked->skipsNotice() && $locked->noticeDays($lastDay) < Resignation::NOTICE_DAYS,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            $this->audit->record(AuditArea::People, 'resignation.accepted', "Accepted {$locked->user->name}'s resignation, last day ".$lastDay->format('M j, Y'), $locked, [
                'new' => ['last_day' => $lastDay->toDateString(), 'notice_waived' => $locked->notice_waived],
            ], causer: $actor);

            $locked->user->notify(new ResignationNotice($locked, ResignationNotice::ACCEPTED));

            // A guard leaves a post: Operation HQ and the region's Satellite plan who covers it.
            if ($locked->isGuard()) {
                $region = $locked->user->employeeProfile?->client?->region;
                $operations = User::query()->notifiable([Role::OperationsHq, Role::SatelliteOperations])->with('roles')->get()
                    ->filter(fn (User $user) => $user->hasRole(Role::OperationsHq) || ($region !== null && $user->operations_region === $region))
                    ->values();
                Notification::send($operations, new ResignationNotice($locked, ResignationNotice::ACCEPTED_OPERATIONS));
            }

            return $locked;
        });
    }
}
