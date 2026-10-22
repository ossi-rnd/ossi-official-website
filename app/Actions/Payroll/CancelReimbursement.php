<?php

namespace App\Actions\Payroll;

use App\Enums\AuditArea;
use App\Enums\ReimbursementStatus;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The employee takes back a reimbursement still waiting for approval; for a
 * deployment claim, Operations who filed it does (the guard didn't file it).
 */
final class CancelReimbursement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, Reimbursement $reimbursement): Reimbursement
    {
        if ($reimbursement->filedByUserId() !== $employee->id) {
            throw new AuthorizationException;
        }

        if ($reimbursement->status !== ReimbursementStatus::Pending) {
            throw ValidationException::withMessages(['reimbursement' => 'Only a request still waiting for approval can be cancelled.']);
        }

        $reimbursement->forceFill(['status' => ReimbursementStatus::Cancelled])->save();
        $this->audit->record(AuditArea::Payroll, 'reimbursement.cancelled', "{$employee->name} cancelled a reimbursement of ₱".number_format((float) $reimbursement->amount, 2), $reimbursement, causer: $employee);

        return $reimbursement;
    }
}
