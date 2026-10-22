<?php

use App\Actions\Leave\EndorseLeaveRequest;
use App\Actions\Leave\ReviewLeaveRequest;
use App\Enums\LeaveStage;
use App\Enums\Region;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Leave\MyLeave;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveAwaitingYou;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// A guard's leave: the region's Satellite Operation endorses to Operation HQ,
// Operation HQ endorses to HR, and HR decides (final).

function chainGuard(Region $region, string $name): User
{
    $site = Client::query()->firstOrCreate(['region' => $region->value, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $guard = employee(['client_id' => $site->id, 'detachment' => $site->name]);
    $guard->forceFill(['name' => $name])->save();

    return $guard;
}

function chainSatellite(Region $region, string $name): User
{
    $user = staff(Role::SatelliteOperations);
    $user->forceFill(['operations_region' => $region, 'name' => $name])->save();

    return $user;
}

function guardFiles(User $guard): LeaveRequest
{
    Livewire::actingAs($guard)->test(MyLeave::class)
        ->set('type', 'vacation')->set('start_date', '2026-10-15')->set('end_date', '2026-10-16')->set('reason', 'Fiesta in the province')
        ->call('submit')->assertHasNoErrors();

    return LeaveRequest::query()->where('user_id', $guard->id)->latest('id')->firstOrFail();
}

function leaveBadge(User $user): int
{
    return collect(Navigation::for($user))->pluck('items')->flatten(1)->firstWhere('route', 'leave-requests')['badge'] ?? 0;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00'));
    Notification::fake();

    $this->guard = chainGuard(Region::Calabarzon, 'Gary Guard');
    $this->satellite = chainSatellite(Region::Calabarzon, 'Sally Satellite');
    $this->otherSatellite = chainSatellite(Region::CentralVisayas, 'Cebu Satellite');
    $this->opsHq = staff(Role::OperationsHq);
    $this->opsHq->forceFill(['name' => 'Oscar HQ'])->save();
    $this->hr = staff(Role::Hr);
    $this->hr->forceFill(['name' => 'Hannah HR'])->save();
    $this->admin = staff(Role::Admin);
});

it('goes Satellite Operation → Operation HQ → HR, telling each step, and HR has the final say', function () {
    $leave = guardFiles($this->guard);
    $sentTo = fn (User $user) => Notification::sent($user, LeaveAwaitingYou::class)->count();

    // Filed: with the region's Satellite Operation only.
    expect($leave->stage)->toBe(LeaveStage::Satellite)
        ->and($sentTo($this->satellite))->toBe(1)
        ->and($sentTo($this->otherSatellite) + $sentTo($this->opsHq) + $sentTo($this->hr))->toBe(0)
        ->and(leaveBadge($this->satellite))->toBe(1)
        ->and(leaveBadge($this->hr))->toBe(0);

    // HR can't decide yet; another region's Satellite can't endorse it.
    expect(ReviewLeaveRequest::blockedReason($leave, $this->hr))->toBe('Waiting for Satellite Operation to endorse it.')
        ->and(fn () => app(ReviewLeaveRequest::class)->handle($leave, $this->hr, RequestStatus::Approved, null))->toThrow(ValidationException::class)
        ->and(EndorseLeaveRequest::endorsesAs($leave, $this->otherSatellite))->toBeNull();

    // 1. The Satellite Operation endorses to Operation HQ.
    Livewire::actingAs($this->satellite)->test(LeaveRequests::class)
        ->assertSee('With Satellite Operation')
        ->call('startEndorse', $leave->id)->assertSee('Send to Operation HQ')
        ->set('endorseNote', 'Reliever lined up')->call('endorse')->assertHasNoErrors();

    expect($leave->fresh()?->stage)->toBe(LeaveStage::OperationsHq)->and($sentTo($this->opsHq))->toBe(1);
    Livewire::actingAs($this->guard)->test(MyLeave::class)->assertSee('With Operation HQ');

    // 2. Operation HQ doesn't recommend it: a reason is required, and it still goes up to HR.
    Livewire::actingAs($this->opsHq)->test(LeaveRequests::class)
        ->call('startEndorse', $leave->id)
        ->set('endorseRecommends', false)->call('endorse')->assertHasErrors('endorseNote')
        ->set('endorseNote', 'Two guards already off at this post that week')->call('endorse')->assertHasNoErrors();

    expect($leave->fresh()?->stage)->toBe(LeaveStage::Hr)->and($sentTo($this->hr))->toBe(1);

    // Admin no longer approves guards' leave: HR does.
    expect(ReviewLeaveRequest::blockedReason($leave->fresh() ?? $leave, $this->admin))->toBe('Guards\' leave is approved by HR.');

    // 3. HR sees both endorsements and decides.
    Livewire::actingAs($this->hr)->test(LeaveRequests::class)
        ->call('startReview', $leave->id)
        ->assertSee('Sally Satellite')->assertSee('Reliever lined up')
        ->assertSee('Not recommended')->assertSee('Two guards already off at this post that week')
        ->call('approve')->assertHasNoErrors();

    $leave->refresh();
    expect($leave->status)->toBe(RequestStatus::Approved)
        ->and($leave->stage)->toBeNull()
        ->and($leave->reviewed_by)->toBe($this->hr->id)
        ->and($leave->endorsements()->pluck('stage')->all())->toBe([LeaveStage::Satellite, LeaveStage::OperationsHq]);
});

it('lets Operation HQ endorse straight to HR when the Satellite hasn\'t', function () {
    $leave = guardFiles($this->guard);

    Livewire::actingAs($this->opsHq)->test(LeaveRequests::class)
        ->call('startEndorse', $leave->id)->assertSee('yours goes straight to HR')
        ->call('endorse')->assertHasNoErrors();

    expect($leave->fresh()?->stage)->toBe(LeaveStage::Hr)
        ->and(EndorseLeaveRequest::endorsesAs($leave->fresh() ?? $leave, $this->satellite))->toBeNull() // too late for the Satellite
        ->and(ActivityLog::query()->where('event', 'leave.endorsed')->value('description'))->toContain('Satellite step skipped');
});

it('starts at Operation HQ when the guard\'s region has no Satellite Operation', function () {
    $visayasGuard = chainGuard(Region::WesternVisayas, 'Iloilo Guard');

    expect(guardFiles($visayasGuard)->stage)->toBe(LeaveStage::OperationsHq);
    Notification::assertSentTo($this->opsHq, LeaveAwaitingYou::class);
});

it('does not let anyone endorse their own leave or act out of turn', function () {
    $leave = guardFiles($this->guard);

    // Operation HQ can act early (skip), but HR can't, and Satellite can't twice.
    app(EndorseLeaveRequest::class)->handle($leave, $this->satellite, true, null);
    expect(fn () => app(EndorseLeaveRequest::class)->handle($leave, $this->satellite, true, null))->toThrow(ValidationException::class, 'isn\'t waiting for your endorsement')
        ->and(fn () => app(EndorseLeaveRequest::class)->handle($leave, $this->hr, true, null))->toThrow(ValidationException::class);
});
