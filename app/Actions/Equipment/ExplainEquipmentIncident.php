<?php

namespace App\Actions\Equipment;

use App\Enums\AuditArea;
use App\Models\EquipmentIncident;
use App\Models\User;
use App\Notifications\UpdateNotice;
use App\Services\AuditLogger;
use App\Support\Heads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** The employee's side, once, within the days given. Whoever reported it and their head are told. */
final class ExplainEquipmentIncident
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $employee, EquipmentIncident $incident, string $explanation): EquipmentIncident
    {
        $incident->loadMissing('issue');

        if ($incident->issue->user_id !== $employee->id) {
            throw new AuthorizationException;
        }

        $explanation = trim($explanation);

        if (mb_strlen($explanation) < 10) {
            throw ValidationException::withMessages(['explanation' => 'Write what happened, in your own words.']);
        }

        return DB::transaction(function () use ($employee, $incident, $explanation) {
            $locked = EquipmentIncident::query()->with(['issue', 'reporter'])->lockForUpdate()->findOrFail($incident->id);

            if (! $locked->canStillExplain()) {
                throw ValidationException::withMessages(['explanation' => 'The days to explain have passed, or you already explained.']);
            }

            $locked->forceFill(['explanation' => mb_substr($explanation, 0, 3000), 'explained_at' => now()])->save();

            $this->audit->record(AuditArea::Operations, 'equipment.incident_explained', "{$employee->name} explained about {$locked->issue->describe()}", $locked, causer: $employee);

            $tell = Heads::of($employee)->push($locked->reporter)->unique('id')->reject(fn (User $user) => $user->is($employee));
            Notification::send($tell, new UpdateNotice("Explained: {$locked->issue->describe()}", "{$employee->name}: {$explanation}", route('equipment', ['show' => 'incidents']), 'package'));

            return $locked;
        });
    }
}
