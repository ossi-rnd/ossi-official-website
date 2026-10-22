<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Notifications\DeductionAuthorizationNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The employee answers a deduction Accounting asked them to authorize (Art. 113).
 * Authorized: their sentence is saved word for word with the time, and payslips
 * take it from now on. Declined: nothing is taken; Accounting is told either way.
 */
final class AnswerDeductionAuthorization
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, EmployeeDeduction $deduction, bool $authorize, string $locale = 'en'): EmployeeDeduction
    {
        if ($deduction->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($employee, $deduction, $authorize, $locale) {
            $locked = EmployeeDeduction::query()->lockForUpdate()->findOrFail($deduction->id);

            if (! $locked->awaiting_authorization || $locked->declined_at !== null) {
                throw ValidationException::withMessages(['deduction' => 'This one was already answered.']);
            }

            if ($authorize) {
                $locked->forceFill(['awaiting_authorization' => false, 'authorization' => $locked->authorizationText($locale), 'authorized_at' => now()])->save();
            } else {
                $locked->forceFill(['declined_at' => now()])->save();
            }

            $this->audit->record(AuditArea::Payroll, $authorize ? 'deduction.authorized' : 'deduction.declined', ($authorize ? "{$employee->name} authorized" : "{$employee->name} declined")." {$locked->type->label()} \"{$locked->label}\"", $locked, causer: $employee);

            Notification::send(
                User::query()->notifiable(Ability::ManagePayroll->roles())->whereKeyNot($employee->id)->get(),
                new DeductionAuthorizationNotice($locked->setRelation('user', $employee), $authorize ? DeductionAuthorizationNotice::AUTHORIZED : DeductionAuthorizationNotice::DECLINED),
            );

            return $locked;
        });
    }
}
