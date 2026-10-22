<?php

namespace App\Actions\Auth;

use App\Actions\Payroll\EnrollCashBond;
use App\Enums\AuditArea;
use App\Enums\RegistrationFailure;
use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use App\Support\EmployeeNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registration is not "anyone can sign up". HR adds the employee first
 * (employee number + email); registering CLAIMS that record:
 *
 *   1. the employee number exists,
 *   2. nobody has claimed it yet,
 *   3. the email matches the one HR recorded,
 *   4. the employee is still active,
 *   5. no other account already uses the email.
 *
 * Every attempt, successful or not, is written to the audit log. A failure
 * always shows the SAME message, so the form can't be used to discover which
 * employee numbers exist.
 */
final class ClaimEmployeeAccount
{
    public const FAILURE_MESSAGE = 'We could not match these details to an employee record from HR. Check your employee number and the email you gave HR, or contact HR.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EnrollCashBond $enrollCashBond,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $employeeNumber, string $email, string $password): User
    {
        $employeeNumber = EmployeeNumber::normalize($employeeNumber);
        $email = Str::lower(trim($email));

        /** @var array{0: User|RegistrationFailure, 1: EmployeeProfile|null} $result */
        $result = DB::transaction(function () use ($employeeNumber, $email, $password): array {
            // Locked so two people racing to claim the same number can't both win.
            $profile = EmployeeProfile::query()
                ->where('employee_number', $employeeNumber)
                ->lockForUpdate()
                ->first();

            $failure = $this->check($profile, $email);

            if ($failure !== null || $profile === null) {
                return [$failure ?? RegistrationFailure::UnknownEmployeeNumber, $profile];
            }

            $user = User::create([
                'name' => $profile->name,
                // A test account keeps the number's capitals, as HR's record shows it.
                'email' => $profile->is_test_account ? $profile->email : $email,
                'password' => $password,
            ]);
            $user->roles()->create(['role' => Role::Employee]);

            // A role the Super Admin set in advance (Roles & Access → Not registered yet).
            $pendingRole = $profile->pending_role;
            if ($pendingRole !== null) {
                $user->roles()->create(['role' => $pendingRole]);
                $user->forceFill(['operations_region' => $pendingRole === Role::SatelliteOperations ? $profile->pending_region : null])->save();
                $profile->forceFill(['pending_role' => null, 'pending_region' => null]);
            }

            // A test account's address has no mailbox (HR chose it): confirmed now, no link sent.
            if ($profile->is_test_account) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $profile->user()->associate($user);
            $profile->save();

            // Guards pay a cash bond from their first payslip (₱100 a cut-off), refunded in final pay.
            if ($profile->hasDeployment()) {
                $this->enrollCashBond->handle($user, $user);
            }

            $this->audit->record(
                AuditArea::Auth,
                'registration.succeeded',
                "{$profile->name} registered an account",
                $profile,
                ['employee_number' => $employeeNumber, 'email' => $email, 'role' => $pendingRole?->value],
                causer: $user,
            );

            // HR knows onboarding is done: they can now be deployed, rostered and paid.
            Notification::send(User::query()->notifiable([Role::Hr])->whereKeyNot($user->id)->get(), new UpdateNotice(
                "{$profile->name} registered",
                "Employee no. {$profile->employee_number} created their account".($pendingRole !== null ? " and has the {$pendingRole->label()} role" : '').'.',
                route('employees.show', $profile), 'user-plus',
            ));

            return [$user, $profile];
        });

        [$outcome, $profile] = $result;

        if ($outcome instanceof RegistrationFailure) {
            // Outside the transaction on purpose: the failure must be recorded even though nothing else is saved.
            $this->audit->record(
                AuditArea::Auth,
                'registration.failed',
                'Registration refused',
                $profile,
                ['employee_number' => $employeeNumber, 'email' => $email, 'reason' => $outcome->value],
            );

            throw ValidationException::withMessages(['employee_number' => self::FAILURE_MESSAGE]);
        }

        return $outcome;
    }

    private function check(?EmployeeProfile $profile, string $email): ?RegistrationFailure
    {
        return match (true) {
            $profile === null => RegistrationFailure::UnknownEmployeeNumber,
            $profile->isClaimed() => RegistrationFailure::AlreadyClaimed,
            ! hash_equals(Str::lower($profile->email), $email) => RegistrationFailure::EmailMismatch,
            ! $profile->employment_status->isActive() => RegistrationFailure::NotActive,
            User::query()->whereRaw('lower(email) = ?', [$email])->exists() => RegistrationFailure::EmailTaken,
            default => null,
        };
    }
}
