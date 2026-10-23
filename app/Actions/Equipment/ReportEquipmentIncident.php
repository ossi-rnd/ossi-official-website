<?php

namespace App\Actions\Equipment;

use App\Enums\AuditArea;
use App\Enums\EquipmentIncidentKind;
use App\Enums\EquipmentStatus;
use App\Enums\RadioStatus;
use App\Models\EquipmentIncident;
use App\Models\EquipmentIssue;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reports company property damaged or lost: the start of due process. The employee is told
 * (bell and email) what was reported and that they may explain in writing within
 * EquipmentIncident::EXPLAIN_DAYS days. Nothing is charged here. A radio is marked
 * "For repair" or "Lost" on the masterlist.
 */
final class ReportEquipmentIncident
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $reporter, EquipmentIssue $issue, EquipmentIncidentKind $kind, string $details): EquipmentIncident
    {
        $issue->loadMissing('user');

        if (! EquipmentIssue::canIssueTo($reporter, $issue->user) && ! EquipmentIncident::canDecide($reporter, $issue->user)) {
            throw new AuthorizationException;
        }

        $details = trim($details);

        if (mb_strlen($details) < 20) {
            throw ValidationException::withMessages(['incidentDetails' => 'Say what happened: when, where, and what is damaged or missing.']);
        }

        return DB::transaction(function () use ($reporter, $issue, $kind, $details) {
            $locked = EquipmentIssue::query()->with(['user', 'radio', 'incidents'])->lockForUpdate()->findOrFail($issue->id);

            if ($locked->status !== EquipmentStatus::Issued || $locked->charged) {
                throw ValidationException::withMessages(['incidentDetails' => 'Only company property still with them can be reported.']);
            }

            if ($locked->openIncident() !== null) {
                throw ValidationException::withMessages(['incidentDetails' => 'There is already an open report for this item.']);
            }

            $incident = new EquipmentIncident;
            $incident->forceFill([
                'equipment_issue_id' => $locked->id,
                'kind' => $kind,
                'details' => mb_substr($details, 0, 2000),
                'reported_by' => $reporter->id,
                'explain_by' => CarbonImmutable::today()->addDays(EquipmentIncident::EXPLAIN_DAYS)->toDateString(),
            ])->save();

            $locked->radio?->forceFill(['status' => $kind === EquipmentIncidentKind::Lost ? RadioStatus::Lost : RadioStatus::ForRepair])->save();

            $this->audit->record(AuditArea::Operations, 'equipment.incident_reported', "Reported {$locked->describe()} of {$locked->user->name} as {$kind->value}", $incident, ['new' => ['details' => $details]], causer: $reporter);

            UpdateNotice::tell($locked->user, $reporter, "Reported {$kind->value}: {$locked->describe()}",
                "{$details}\nYou may explain in writing on My Equipment until ".$incident->explain_by->format('M j, Y').'. Nothing is charged before you are heard.',
                route('my-equipment'), 'package', email: true);

            return $incident;
        });
    }
}
