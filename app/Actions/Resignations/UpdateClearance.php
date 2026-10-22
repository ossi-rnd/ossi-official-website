<?php

namespace App\Actions\Resignations;

use App\Enums\AuditArea;
use App\Enums\ClearanceItem;
use App\Enums\ResignationStatus;
use App\Models\Resignation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticks (or unticks) one clearance item, with who and when. Once the person
 * has left (separated) and every item is ticked, the resignation completes.
 */
final class UpdateClearance
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CompleteResignation $complete,
    ) {}

    public function handle(Resignation $resignation, ClearanceItem $item, bool $done, User $actor): Resignation
    {
        if (! $actor->can($resignation->handlerAbility()->value)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($resignation, $item, $done, $actor) {
            $locked = Resignation::query()->with('user.employeeProfile')->lockForUpdate()->findOrFail($resignation->id);

            if ($locked->status !== ResignationStatus::Accepted) {
                throw ValidationException::withMessages(['clearance' => 'Clearance runs once a resignation is accepted, until it\'s complete.']);
            }

            if (! in_array($item, $locked->clearanceItems(), true)) {
                throw ValidationException::withMessages(['clearance' => 'That item doesn\'t apply to this person.']);
            }

            $clearance = $locked->clearance ?? [];

            if ($done) {
                $clearance[$item->value] = ['at' => now()->toIso8601String(), 'by' => $actor->name];
            } else {
                unset($clearance[$item->value]);
            }

            $locked->forceFill(['clearance' => $clearance])->save();
            $this->audit->record(AuditArea::People, $done ? 'resignation.cleared' : 'resignation.uncleared', ($done ? 'Cleared' : 'Reopened')." \"{$item->label()}\" for {$locked->user->name}", $locked, causer: $actor);

            return $this->complete->handle($locked);
        });
    }
}
