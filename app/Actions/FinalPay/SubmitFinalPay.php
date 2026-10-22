<?php

namespace App\Actions\FinalPay;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\FinalPayStatus;
use App\Enums\Role;
use App\Models\FinalPay;
use App\Models\User;
use App\Notifications\FinalPayNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Accounting sends a prepared statement for signatures: frozen from here.
 * Accounting is asked to sign; the President or Vice President is asked once Accounting has.
 */
final class SubmitFinalPay
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(FinalPay $finalPay, User $actor): FinalPay
    {
        if (! $actor->can(Ability::PrepareFinalPay->value)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($finalPay, $actor) {
            $locked = FinalPay::query()->with('user')->lockForUpdate()->findOrFail($finalPay->id);

            if ($locked->status !== FinalPayStatus::Draft) {
                throw ValidationException::withMessages(['finalPay' => 'Only a statement being prepared can be submitted.']);
            }

            $locked->forceFill(['status' => FinalPayStatus::Signing, 'signoffs' => [], 'submitted_at' => now(), 'return_note' => null, 'returned_by' => null])->save();
            $this->audit->record(AuditArea::Payroll, 'final_pay.submitted', "Sent {$locked->user->name}'s final pay for signatures", $locked, causer: $actor);

            // Everyone before the President or Vice President (who are asked last).
            $first = array_filter(FinalPay::SIGNERS, fn (Role $role) => $role !== Role::President);
            Notification::send(self::holders($first, $locked), new FinalPayNotice($locked, FinalPayNotice::TO_SIGN));

            return $locked;
        });
    }

    /**
     * Active holders of these roles, never the person whose final pay it is.
     *
     * @param  list<Role>  $roles
     * @return Collection<int, User>
     */
    public static function holders(array $roles, FinalPay $finalPay): Collection
    {
        return User::query()->notifiable($roles)->whereKeyNot($finalPay->user_id)->get();
    }
}
