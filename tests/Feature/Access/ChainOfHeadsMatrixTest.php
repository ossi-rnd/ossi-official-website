<?php

use App\Actions\Leave\EndorseLeaveRequest;
use App\Actions\Leave\ReviewLeaveRequest;
use App\Actions\Overtime\ReviewOvertimeRequest;
use App\Actions\Patrols\ApproveAway;
use App\Enums\LeaveStage;
use App\Enums\Region;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AwayLog;
use App\Models\Client;
use App\Models\LeaveRequest;
use App\Models\MeritRecord;
use App\Models\NoticeToExplain;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Support\Heads;
use Illuminate\Validation\ValidationException;

// Who is above whom, checked both ways. The owner's chain (2026-10-23):
//   HR, Operation HQ, Accounting → the President or VP · Admin and others → HR · a Satellite → Operation HQ
//   a guard → endorsed by their region's Satellite and Operation HQ, decided by HR
// (Operation HQ and Accounting moved from HR to the President or VP, the usual line in an agency.)
// A head may decide for the people under them, never upward, never a peer, never themselves.
// The Super Admin is the backup for everyone.

beforeEach(function () {
    $this->superAdmin = staff(Role::SuperAdmin);
    $this->president = staff(Role::President);
    $this->vp = staff(Role::VicePresident);
    $this->hr = staff(Role::Hr);
    $this->hr2 = staff(Role::Hr);
    $this->hq = staff(Role::OperationsHq);
    $this->admin = staff(Role::Admin);
    $this->clerk = staff(Role::Accounting);
    $this->ncrSatellite = staff(Role::SatelliteOperations);
    $this->ncrSatellite->forceFill(['operations_region' => Region::Ncr])->save();
    $this->calabarzonSatellite = staff(Role::SatelliteOperations);
    $this->calabarzonSatellite->forceFill(['operations_region' => Region::Calabarzon])->save();

    $site = Client::query()->create(['region' => Region::Ncr->value, 'province' => 'Metro Manila', 'name' => 'BDO – Makati']);
    $this->guard = employee(['client_id' => $site->id, 'detachment' => $site->name]);
});

/** Every place a head acts for an office staffer, asked at once. */
function headPowers(User $actor, User $person): array
{
    $leave = (new LeaveRequest)->forceFill(['user_id' => $person->id, 'stage' => LeaveStage::Hr])->setRelation('user', $person);
    $overtime = (new OvertimeRequest)->forceFill(['user_id' => $person->id])->setRelation('user', $person);
    $away = (new AwayLog)->forceFill(['user_id' => $person->id])->setRelation('user', $person);

    return [
        'decides leave' => ReviewLeaveRequest::blockedReason($leave, $actor) === null,
        'decides overtime' => ReviewOvertimeRequest::blockedReason($overtime, $actor) === null,
        'approves time away' => ApproveAway::canApprove($away, $actor),
        'issues a Notice to Explain' => NoticeToExplain::canIssue($actor, $person),
        'gives a merit' => MeritRecord::canGive($actor, $person),
        'is their head' => Heads::isHeadOf($actor, $person),
    ];
}

it('lets each office head act for the people under them, in every flow', function (string $actor, string $person) {
    expect(headPowers($this->{$actor}, $this->{$person}))->each->toBeTrue();
})->with([
    'President → HR' => ['president', 'hr'],
    'Vice President → HR' => ['vp', 'hr'],
    'President → Operation HQ' => ['president', 'hq'],
    'Vice President → Operation HQ' => ['vp', 'hq'],
    'President → Accounting' => ['president', 'clerk'],
    'Vice President → Accounting' => ['vp', 'clerk'],
    'HR → Admin' => ['hr', 'admin'],
    'Operation HQ → Satellite' => ['hq', 'ncrSatellite'],
    'Super Admin (backup) → HR' => ['superAdmin', 'hr'],
    'Super Admin (backup) → Accounting' => ['superAdmin', 'clerk'],
    'Super Admin (backup) → Satellite' => ['superAdmin', 'ncrSatellite'],
]);

it('never lets anyone act upward, sideways, across the chain, or for themselves', function (string $actor, string $person) {
    expect(headPowers($this->{$actor}, $this->{$person}))->each->toBeFalse();
})->with([
    // Upward
    'HR ✗ the President' => ['hr', 'president'],
    'Accounting ✗ the Vice President' => ['clerk', 'vp'],
    'Admin ✗ HR' => ['admin', 'hr'],
    'Satellite ✗ Operation HQ' => ['ncrSatellite', 'hq'],
    // Sideways (peers: HR, Operation HQ and Accounting all answer to the President or VP)
    'HR ✗ another HR' => ['hr', 'hr2'],
    'HR ✗ Operation HQ' => ['hr', 'hq'],
    'HR ✗ Accounting' => ['hr', 'clerk'],
    'Operation HQ ✗ HR' => ['hq', 'hr'],
    'Accounting ✗ HR' => ['clerk', 'hr'],
    'Operation HQ ✗ Accounting' => ['hq', 'clerk'],
    'Accounting ✗ Operation HQ' => ['clerk', 'hq'],
    'Satellite ✗ another Satellite' => ['calabarzonSatellite', 'ncrSatellite'],
    // Across the chain: a head is a head only of their own people
    'HR ✗ Satellite (Operation HQ is their head)' => ['hr', 'ncrSatellite'],
    'Operation HQ ✗ Admin (HR is their head)' => ['hq', 'admin'],
    'Accounting ✗ Admin' => ['clerk', 'admin'],
    'Admin ✗ Accounting' => ['admin', 'clerk'],
    'President ✗ Admin (monitors only)' => ['president', 'admin'],
    'Vice President ✗ Satellite (monitors only)' => ['vp', 'ncrSatellite'],
    'Satellite ✗ Accounting' => ['ncrSatellite', 'clerk'],
    // Never yourself
    'HR ✗ themselves' => ['hr', 'hr'],
    'Operation HQ ✗ themselves' => ['hq', 'hq'],
    'Accounting ✗ themselves' => ['clerk', 'clerk'],
]);

it('tells a blocked reviewer who the real head is', function () {
    $leave = (new LeaveRequest)->forceFill(['user_id' => $this->hr->id])->setRelation('user', $this->hr);
    $overtime = (new OvertimeRequest)->forceFill(['user_id' => $this->ncrSatellite->id])->setRelation('user', $this->ncrSatellite);

    expect(ReviewLeaveRequest::blockedReason($leave, $this->hr2))->toBe('Their head decides it: '.Role::LEADERSHIP_LABEL.'.')
        ->and(ReviewOvertimeRequest::blockedReason($overtime, $this->hr))->toBe('Their head decides it: Operation HQ.')
        ->and(ReviewLeaveRequest::blockedReason($leave->setRelation('user', $this->hr)->forceFill(['user_id' => $this->hr->id]), $this->hr))
        ->toBe('You cannot review your own request.');
});

it('refuses an upward decision in the Action itself, not only in the screen', function () {
    $overtime = new OvertimeRequest;
    $overtime->forceFill(['user_id' => $this->hr->id, 'date' => '2026-10-08', 'requested_minutes' => 120, 'reason' => 'Payroll cut-off', 'supervisor_id' => $this->president->id, 'status' => RequestStatus::Pending])->save();

    expect(fn () => app(ReviewOvertimeRequest::class)->handle($overtime, $this->clerk, RequestStatus::Approved, null))
        ->toThrow(ValidationException::class);
    expect($overtime->fresh()?->status)->toBe(RequestStatus::Pending);
});

it('keeps a guard\'s leave climbing Satellite → Operation HQ → HR, each only at their own step', function () {
    $leave = (new LeaveRequest)->forceFill(['user_id' => $this->guard->id, 'status' => RequestStatus::Pending, 'stage' => LeaveStage::Satellite])->setRelation('user', $this->guard);

    // At the Satellite step: their region's Satellite (or Operation HQ, skipping it) endorses; nobody decides yet.
    expect(EndorseLeaveRequest::endorsesAs($leave, $this->ncrSatellite))->toBe(LeaveStage::Satellite)
        ->and(EndorseLeaveRequest::endorsesAs($leave, $this->calabarzonSatellite))->toBeNull()
        ->and(EndorseLeaveRequest::endorsesAs($leave, $this->hq))->toBe(LeaveStage::OperationsHq)
        ->and(EndorseLeaveRequest::endorsesAs($leave, $this->hr))->toBeNull()
        ->and(ReviewLeaveRequest::blockedReason($leave, $this->hr))->not->toBeNull();

    // At HR's step: HR decides; the endorsers can't, and the President or VP don't for a guard.
    $leave->stage = LeaveStage::Hr;
    expect(ReviewLeaveRequest::blockedReason($leave, $this->hr))->toBeNull()
        ->and(EndorseLeaveRequest::endorsesAs($leave, $this->hq))->toBeNull()
        ->and(ReviewLeaveRequest::blockedReason($leave, $this->ncrSatellite))->not->toBeNull()
        ->and(ReviewLeaveRequest::blockedReason($leave, $this->clerk))->not->toBeNull();

    // An office staffer's leave is never endorsed: their head decides it outright.
    $office = (new LeaveRequest)->forceFill(['user_id' => $this->clerk->id, 'status' => RequestStatus::Pending, 'stage' => LeaveStage::Hr])->setRelation('user', $this->clerk);
    expect(EndorseLeaveRequest::endorsesAs($office, $this->hq))->toBeNull();
});

it('keeps a Satellite to their own region\'s guards', function () {
    $away = (new AwayLog)->forceFill(['user_id' => $this->guard->id])->setRelation('user', $this->guard);

    expect(Heads::isHeadOf($this->ncrSatellite, $this->guard))->toBeTrue()
        ->and(Heads::isHeadOf($this->calabarzonSatellite, $this->guard))->toBeFalse()
        ->and(NoticeToExplain::canIssue($this->calabarzonSatellite, $this->guard))->toBeFalse()
        ->and(ApproveAway::canApprove($away, $this->calabarzonSatellite))->toBeFalse()
        ->and(ApproveAway::canApprove($away, $this->ncrSatellite))->toBeTrue()
        ->and(Heads::of($this->guard)->modelKeys())->toEqualCanonicalizing([$this->hq->id, $this->ncrSatellite->id]);
});

it('tells only the heads above someone, never a peer, never someone below', function () {
    $leadership = [$this->president->id, $this->vp->id];

    expect(Heads::of($this->hr)->modelKeys())->toEqualCanonicalizing($leadership)
        ->and(Heads::of($this->hq)->modelKeys())->toEqualCanonicalizing($leadership)
        ->and(Heads::of($this->clerk)->modelKeys())->toEqualCanonicalizing($leadership)
        ->and(Heads::of($this->admin)->modelKeys())->toEqualCanonicalizing([$this->hr->id, $this->hr2->id])
        ->and(Heads::of($this->ncrSatellite)->modelKeys())->toBe([$this->hq->id]);
});

it('keeps HR informed (as timekeeper) about office staff it doesn\'t head, but never about HR', function () {
    $hr = [$this->hr->id, $this->hr2->id];

    expect(Heads::toTell($this->hq)->modelKeys())->toEqualCanonicalizing([$this->president->id, $this->vp->id, ...$hr])
        ->and(Heads::toTell($this->clerk)->modelKeys())->toEqualCanonicalizing([$this->president->id, $this->vp->id, ...$hr])
        ->and(Heads::toTell($this->ncrSatellite)->modelKeys())->toEqualCanonicalizing([$this->hq->id, ...$hr])
        ->and(Heads::toTell($this->admin)->modelKeys())->toEqualCanonicalizing($hr) // HR already heads them
        ->and(Heads::toTell($this->hr)->modelKeys())->toEqualCanonicalizing([$this->president->id, $this->vp->id]) // not the other HR
        ->and(Heads::informed($this->guard))->toBeEmpty();
});

it('stops telling a head who no longer works here, and nobody heads the top', function () {
    $this->hr2->employeeProfile?->forceFill(['employment_status' => 'resigned'])->save();

    expect(Heads::of($this->admin)->modelKeys())->toBe([$this->hr->id])
        ->and(Heads::of($this->president))->toBeEmpty()
        ->and(Heads::of($this->superAdmin))->toBeEmpty();
});
