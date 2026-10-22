<?php

namespace App\Actions\Resignations;

use App\Enums\AuditArea;
use App\Enums\ResignationStatus;
use App\Models\Resignation;
use App\Models\User;
use App\Notifications\ResignationNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The employee takes their resignation back. Only while it's pending: once
 * HR has accepted a last day, changing it is a conversation with HR.
 */
final class WithdrawResignation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Resignation $resignation, User $employee): Resignation
    {
        if ($resignation->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($resignation, $employee) {
            $locked = Resignation::query()->lockForUpdate()->findOrFail($resignation->id);

            if ($locked->status !== ResignationStatus::Pending) {
                throw ValidationException::withMessages(['resignation' => 'It has been accepted already. Talk to '.$locked->handlerLabel().' to change it.']);
            }

            $locked->forceFill(['status' => ResignationStatus::Withdrawn])->save();
            $this->audit->record(AuditArea::People, 'resignation.withdrawn', "{$employee->name} withdrew their resignation", $locked, causer: $employee);

            $handlers = User::query()->notifiable($locked->handlerAbility()->roles())->whereKeyNot($employee->id)->get();
            Notification::send($handlers, new ResignationNotice($locked->setRelation('user', $employee), ResignationNotice::WITHDRAWN));

            return $locked;
        });
    }
}
