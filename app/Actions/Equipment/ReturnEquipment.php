<?php

namespace App\Actions\Equipment;

use App\Enums\AuditArea;
use App\Enums\EquipmentStatus;
use App\Enums\RadioStatus;
use App\Models\EquipmentIssue;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes company property back (not a uniform: that's theirs, paid for). A radio goes back in
 * stock on the masterlist, unless it was reported damaged (then it stays "For repair").
 */
final class ReturnEquipment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $receiver, EquipmentIssue $issue, ?string $note): EquipmentIssue
    {
        $issue->loadMissing('user');

        if (! EquipmentIssue::canIssueTo($receiver, $issue->user)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($receiver, $issue, $note) {
            $locked = EquipmentIssue::query()->with(['user', 'radio'])->lockForUpdate()->findOrFail($issue->id);

            if ($locked->status !== EquipmentStatus::Issued || $locked->charged) {
                throw ValidationException::withMessages(['return' => 'Only company property still with them can be returned.']);
            }

            $note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;
            $locked->forceFill(['status' => EquipmentStatus::Returned, 'returned_at' => now(), 'returned_to' => $receiver->id, 'return_note' => $note])->save();

            if ($locked->radio !== null && $locked->radio->assigned_user_id === $locked->user_id) {
                $locked->radio->forceFill([
                    'assigned_user_id' => null,
                    'client_id' => null,
                    'status' => $locked->radio->status === RadioStatus::ForRepair ? RadioStatus::ForRepair : RadioStatus::Spare,
                ])->save();
            }

            $this->audit->record(AuditArea::Operations, 'equipment.returned', "{$locked->user->name} returned {$locked->describe()}", $locked, ['new' => ['note' => $note]], causer: $receiver);

            UpdateNotice::tell($locked->user, $receiver, 'Equipment returned', "{$locked->describe()} is recorded as returned to {$receiver->name}.", route('my-equipment'), 'package');

            return $locked;
        });
    }
}
