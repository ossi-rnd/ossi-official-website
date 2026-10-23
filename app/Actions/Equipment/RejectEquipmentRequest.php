<?php

namespace App\Actions\Equipment;

use App\Enums\AuditArea;
use App\Enums\EquipmentStatus;
use App\Models\EquipmentIssue;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Says no to a request, with the reason; the employee is told. */
final class RejectEquipmentRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $issuer, EquipmentIssue $request, string $reason): EquipmentIssue
    {
        $request->loadMissing('user');

        if (! EquipmentIssue::canIssueTo($issuer, $request->user)) {
            throw new AuthorizationException;
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['rejectReason' => 'Say why, so they know.']);
        }

        return DB::transaction(function () use ($issuer, $request, $reason) {
            $locked = EquipmentIssue::query()->with('user')->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== EquipmentStatus::Requested) {
                throw ValidationException::withMessages(['rejectReason' => 'That request was already answered.']);
            }

            $locked->forceFill(['status' => EquipmentStatus::Rejected, 'reject_reason' => mb_substr($reason, 0, 500), 'issued_by' => $issuer->id])->save();

            $this->audit->record(AuditArea::Operations, 'equipment.rejected', "Did not approve {$locked->user->name}'s request for {$locked->describe()}", $locked, ['new' => ['reason' => $reason]], causer: $issuer);

            UpdateNotice::tell($locked->user, $issuer, 'Equipment request not approved', "{$locked->describe()}\nReason: {$reason}", route('my-equipment'), 'package');

            return $locked;
        });
    }
}
