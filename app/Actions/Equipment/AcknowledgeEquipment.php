<?php

namespace App\Actions\Equipment;

use App\Enums\AuditArea;
use App\Enums\EquipmentStatus;
use App\Models\EquipmentIssue;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Acknowledgements;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The employee confirms they received an item. For company property that is also their
 * acknowledgement of accountability; for a uniform, of its price (the deduction itself is
 * authorized separately on My Payslips). The sentence is kept word for word, in the
 * language they read it in.
 */
final class AcknowledgeEquipment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function text(EquipmentIssue $issue, string $locale): string
    {
        return $issue->charged
            ? Acknowledgements::line('equipment.charged', $locale, ['what' => $issue->describe(), 'total' => '₱'.number_format($issue->total(), 2)])
            : Acknowledgements::line('equipment.company', $locale, ['what' => $issue->describe()]);
    }

    public function handle(User $employee, EquipmentIssue $issue, string $locale = 'en'): EquipmentIssue
    {
        if ($issue->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($employee, $issue, $locale) {
            $locked = EquipmentIssue::query()->lockForUpdate()->findOrFail($issue->id);

            if ($locked->status !== EquipmentStatus::Issued || $locked->acknowledged_at !== null) {
                throw ValidationException::withMessages(['acknowledge' => 'Already confirmed.']);
            }

            $locked->forceFill(['acknowledged_at' => now(), 'acknowledgement' => self::text($locked, $locale)])->save();

            $this->audit->record(AuditArea::Operations, 'equipment.acknowledged', "{$employee->name} confirmed receiving {$locked->describe()}", $locked, causer: $employee);

            return $locked;
        });
    }
}
