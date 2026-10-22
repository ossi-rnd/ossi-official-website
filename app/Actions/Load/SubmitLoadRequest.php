<?php

namespace App\Actions\Load;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\LoadRequestStatus;
use App\Models\LoadRequest;
use App\Models\User;
use App\Notifications\LoadRequestNotice;
use App\Services\AuditLogger;
use App\Support\PhilippineFormats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee asks for mobile load on credit; the amount is deducted from
 * their next payslip once an Admin approves.
 *
 * The rules live here, not only in the form: network and amount must be on
 * the configured lists, the number is normalized, only people on payroll can
 * borrow (the deduction needs a payslip), nobody can have more than
 * `max_open_requests` waiting at a time, and the employee must authorize the
 * deduction in writing (Labor Code Art. 113): the text they ticked is saved.
 */
final class SubmitLoadRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{network: string, mobile_number: string, amount: int, authorized?: bool, locale?: string}  $data
     */
    public function handle(User $user, array $data): LoadRequest
    {
        $mobile = PhilippineFormats::mobileNumber($data['mobile_number']);

        $errors = array_filter([
            'network' => array_key_exists($data['network'], (array) config('load.networks')) ? null : 'Choose a network from the list.',
            'amount' => in_array($data['amount'], (array) config('load.amounts'), true) ? null : 'Choose an amount from the list.',
            'mobileNumber' => $mobile === null ? 'Enter a Philippine mobile number, e.g. 0917 123 4567.' : null,
            // Without it, there's no legal basis to take it from their pay.
            'authorized' => ($data['authorized'] ?? false) === true ? null : 'Tick the box to authorize the deduction from your payslip.',
        ]);

        if (! User::query()->whereKey($user->id)->onPayroll()->exists()) {
            $errors['amount'] = 'Borrow Load is for employees on payroll: it is deducted from your payslip.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $data, $mobile) {
            // Lock the person, not the requests: two tabs submitting at once must count each other's request.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (LoadRequest::query()->where('user_id', $user->id)->open()->count() >= (int) config('load.max_open_requests')) {
                throw ValidationException::withMessages(['amount' => 'You already have '.config('load.max_open_requests').' load requests waiting. Please wait for those first.']);
            }

            $loadRequest = new LoadRequest;
            $loadRequest->forceFill([
                'user_id' => $user->id,
                'network' => $data['network'],
                'mobile_number' => $mobile,
                'amount' => $data['amount'],
                'status' => LoadRequestStatus::Pending,
                'deduction_authorization' => LoadRequest::authorizationText($data['amount'], $data['locale'] ?? 'en'),
                'authorized_at' => now(),
            ])->save();

            $this->audit->record(AuditArea::Load, 'load.submitted', "Requested ₱{$loadRequest->amount} {$loadRequest->networkLabel()} load on credit", $loadRequest, [
                'mobile_number' => $mobile,
            ], causer: $user);

            Notification::send(User::query()->notifiable(Ability::ManageLoadRequests->roles())->get(), new LoadRequestNotice($loadRequest, LoadRequestNotice::SUBMITTED));

            return $loadRequest;
        });
    }
}
