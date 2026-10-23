<?php

namespace App\Livewire\Attendance;

use App\Actions\Attendance\SaveAttendanceAlertRules;
use App\Enums\Ability;
use App\Livewire\Concerns\InteractsWithUser;
use App\Support\AttendanceAlertRules;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The attendance alert rules, under the Attendance Log: everyone who sees the log sees
 * them; HR (manage-people) changes them (SaveAttendanceAlertRules, audited).
 */
class AlertRules extends Component
{
    use InteractsWithUser;

    public bool $editing = false;

    public string $clockOutReminder = '';

    public string $clockOutEscalate = '';

    public string $officeClockInReminder = '';

    /** @var list<string> ISO weekday numbers, as the checkboxes send them */
    public array $officeWorkDays = [];

    public function canEdit(): bool
    {
        return $this->currentUser()->can(Ability::ManagePeople->value);
    }

    public function edit(): void
    {
        abort_unless($this->canEdit(), 403);

        $rules = AttendanceAlertRules::all();
        $this->clockOutReminder = (string) $rules['clock_out_reminder_minutes'];
        $this->clockOutEscalate = (string) $rules['clock_out_escalate_minutes'];
        $this->officeClockInReminder = (string) $rules['office_clock_in_reminder_minutes'];
        $this->officeWorkDays = array_map('strval', $rules['office_work_days']);
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function save(SaveAttendanceAlertRules $save): void
    {
        $validated = $this->validate([
            'clockOutReminder' => ['required', 'integer'],
            'clockOutEscalate' => ['required', 'integer'],
            'officeClockInReminder' => ['required', 'integer'],
            'officeWorkDays' => ['array'],
            'officeWorkDays.*' => ['integer', 'between:1,7'],
        ], attributes: ['clockOutReminder' => 'minutes', 'clockOutEscalate' => 'minutes', 'officeClockInReminder' => 'minutes']);

        $save->handle($this->currentUser(), (int) $validated['clockOutReminder'], (int) $validated['clockOutEscalate'], (int) $validated['officeClockInReminder'],
            // Unticked boxes leave gaps in the keys: renumber, so it's a plain list of days.
            array_values(array_map('intval', $validated['officeWorkDays'])));

        $this->editing = false;
        $this->dispatch('notify', message: 'Alert rules saved. The next check uses them.');
    }

    public function render(): View
    {
        return view('livewire.attendance.alert-rules', ['rules' => AttendanceAlertRules::all(), 'days' => AttendanceAlertRules::daysLabel()]);
    }
}
