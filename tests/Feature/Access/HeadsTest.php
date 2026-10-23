<?php

use App\Enums\Role;
use App\Models\MeritRecord;
use App\Models\NoticeToExplain;
use App\Support\Heads;

// The owner's chain of heads (2026-10-23), used for attendance alerts, office staff's leave,
// overtime and time away, and Notices to Explain / merits:
//   guard → Operation HQ + their region's Satellite · HR → President / VP
//   Satellite → Operation HQ · other office staff (Admin, Accounting, Operation HQ…) → HR

beforeEach(function () {
    $this->president = staff(Role::President);
    $this->hr = staff(Role::Hr);
    $this->hq = staff(Role::OperationsHq);
    $this->satellite = staff(Role::SatelliteOperations);
    $this->satellite->forceFill(['operations_region' => 'ncr'])->save();
    $this->admin = staff(Role::Admin);
    $this->clerk = staff(Role::Accounting);
    $this->guard = employee();
});

it('names each person\'s head', function () {
    expect(Heads::label($this->guard))->toBe('Operations')
        ->and(Heads::label($this->hr))->toBe(Role::LEADERSHIP_LABEL)
        ->and(Heads::label($this->satellite))->toBe('Operation HQ')
        ->and(Heads::label($this->clerk))->toBe('HR')
        ->and(Heads::label($this->admin))->toBe('HR')
        ->and(Heads::label($this->hq))->toBe('HR');
});

it('knows who heads whom, and nobody heads themselves', function (string $head, string $person, bool $heads) {
    expect(Heads::isHeadOf($this->{$head}, $this->{$person}))->toBe($heads);
})->with([
    'President → HR' => ['president', 'hr', true],
    'President ✗ Accounting' => ['president', 'clerk', false],
    'HR → Accounting' => ['hr', 'clerk', true],
    'HR → Admin' => ['hr', 'admin', true],
    'HR → Operation HQ' => ['hr', 'hq', true],
    'HR ✗ Satellite' => ['hr', 'satellite', false],
    'Operation HQ → Satellite' => ['hq', 'satellite', true],
    'Operation HQ ✗ Accounting' => ['hq', 'clerk', false],
    'Operation HQ → guard' => ['hq', 'guard', true],
    'HR ✗ guard (decides leave by its own gate)' => ['hr', 'guard', false],
    'HR ✗ HR' => ['hr', 'hr', false],
]);

it('lists the heads to notify, never the person', function () {
    expect(Heads::of($this->clerk)->modelKeys())->toBe([$this->hr->id])
        ->and(Heads::of($this->hr)->modelKeys())->toBe([$this->president->id])
        ->and(Heads::of($this->satellite)->modelKeys())->toBe([$this->hq->id])
        ->and(Heads::of($this->hq)->modelKeys())->toBe([$this->hr->id]);
});

it('has Operation HQ, not HR, issue a Satellite\'s Notice to Explain and merits, and see their record', function () {
    expect(NoticeToExplain::canIssue($this->hq, $this->satellite))->toBeTrue()
        ->and(NoticeToExplain::canIssue($this->hr, $this->satellite))->toBeFalse()
        ->and(NoticeToExplain::canView($this->hr, $this->satellite))->toBeTrue() // HR still reads them
        ->and(MeritRecord::canGive($this->hq, $this->satellite))->toBeTrue()
        ->and(MeritRecord::canGive($this->hr, $this->satellite))->toBeFalse()
        ->and($this->hq->canSeeEmployee($this->satellite))->toBeTrue()
        ->and($this->hq->canSeeEmployee($this->clerk))->toBeFalse();

    $this->actingAs($this->hq)->get(route('employees.show', $this->satellite->employeeProfile))->assertOk();
});
