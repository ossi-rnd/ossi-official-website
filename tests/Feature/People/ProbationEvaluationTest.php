<?php

use App\Actions\People\DecideProbationEvaluation;
use App\Actions\People\EvaluateProbation;
use App\Actions\People\SaveProbationRules;
use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\Role;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Models\ActivityLog;
use App\Models\ProbationEvaluation;
use App\Notifications\ProbationEnding;
use App\Notifications\UpdateNotice;
use App\Support\ProbationRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Probation (owner, 2026-10-24): the head evaluates, HR approves. With no approved decision by
// the last day, Regular by law (Art. 296). Resigned and Terminated only come from processes.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:00'));
    $this->hr = staff(Role::Hr);
    $this->opsHq = staff(Role::OperationsHq);
    // Hired May 1 → probation's last day Nov 1.
    $this->guard = employee(['employment_status' => 'probationary', 'hire_date' => '2026-05-01', 'name' => 'Rico Santos']);
    $this->profile = $this->guard->employeeProfile()->firstOrFail();
});

it('lets the guard\'s head evaluate, asks HR to approve, then makes them Regular and tells them', function () {
    Livewire::actingAs($this->opsHq)->test(EmployeeDetail::class, ['profile' => $this->profile])
        ->assertSee('Last day Nov 1, 2026')->assertSee('Evaluate')
        ->call('startEvaluation')
        ->set('evaluationOutcome', 'pass')->set('evaluationReason', 'On time every duty, good reports.')
        ->call('evaluate')->assertHasNoErrors()
        ->assertSee('Waiting for HR')->assertDontSee('Approve');

    Notification::assertSentTo($this->hr, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === "Approve Rico Santos's probation evaluation");
    expect($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Probationary);

    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['profile' => $this->profile])
        ->assertSee('Approve')->call('approveEvaluation')->assertHasNoErrors();

    expect($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Regular)
        ->and(ActivityLog::query()->whereIn('event', ['probation.evaluated', 'probation.approved', 'probation.regularized'])->count())->toBe(3);
    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'You are now a Regular employee');
    Notification::assertSentTo($this->opsHq, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Probation evaluation approved: Rico Santos');
});

it('serves the written notice on an approved fail, and ends employment after the last day', function () {
    $evaluation = app(EvaluateProbation::class)->handle($this->opsHq, $this->profile, 'fail', 'Slept on post twice (Aug 3, Sep 9) and left the post without a reliever.');
    app(DecideProbationEvaluation::class)->handle($this->hr, $evaluation, approve: true);

    Notification::assertSentTo($this->guard, UpdateNotice::class, fn (UpdateNotice $n) => str_starts_with($n->title, 'Notice: your probation') && str_contains($n->body, 'Slept on post') && str_contains($n->body, 'November 1, 2026'));
    expect($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Probationary);

    // Still employed on the last day; ended the day after.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:00'));
    $this->artisan('app:send-probation-alerts');
    expect($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Probationary);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00'));
    $this->artisan('app:send-probation-alerts')->expectsOutput('Closed 1 probation.');
    expect($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Terminated)
        ->and($evaluation->fresh()?->applied_at)->not->toBeNull();
    Notification::assertSentTo($this->hr, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Employment ended: Rico Santos');
});

it('counts HR\'s own evaluation as approved at once', function () {
    $evaluation = app(EvaluateProbation::class)->handle($this->hr, $this->profile, 'pass', 'Meets every standard.');

    expect($evaluation->status)->toBe(ProbationEvaluation::APPROVED)
        ->and($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Regular);
});

it('lets HR send it back with a reason, and the head evaluate again', function () {
    $evaluation = app(EvaluateProbation::class)->handle($this->opsHq, $this->profile, 'fail', 'Late too often, and two missed patrol rounds this month.');

    expect(fn () => app(DecideProbationEvaluation::class)->handle($this->hr, $evaluation, approve: false))->toThrow(ValidationException::class, 'Tell the head why');
    app(DecideProbationEvaluation::class)->handle($this->hr, $evaluation, approve: false, note: 'Were the patrol standards in his contract? Attach the rounds.');

    Notification::assertSentTo($this->opsHq, UpdateNotice::class, fn (UpdateNotice $n) => str_contains($n->body, 'patrol standards'));
    expect($evaluation->fresh()?->status)->toBe(ProbationEvaluation::RETURNED);

    $again = app(EvaluateProbation::class)->handle($this->opsHq, $this->profile, 'pass', 'Improved after coaching.');
    expect($again->status)->toBe(ProbationEvaluation::PENDING);
});

it('refuses after probation ended: under the law they are already Regular', function () {
    $evaluation = app(EvaluateProbation::class)->handle($this->opsHq, $this->profile, 'fail', 'Slept on post twice (Aug 3, Sep 9) and left the post without a reliever.');

    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00'));
    expect(fn () => app(DecideProbationEvaluation::class)->handle($this->hr, $evaluation, approve: true))->toThrow(ValidationException::class, 'they are Regular')
        ->and(fn () => app(EvaluateProbation::class)->handle($this->hr, $this->profile, 'pass', 'Late, but fine.'))->toThrow(ValidationException::class, 'already Regular');

    // The waiting fail is closed, and they become Regular.
    $this->artisan('app:send-probation-alerts');
    expect($this->profile->fresh()?->employment_status)->toBe(EmploymentStatus::Regular)
        ->and($evaluation->fresh()?->status)->toBe(ProbationEvaluation::RETURNED);
});

it('lets only the head or HR evaluate, never the employee or anyone else', function () {
    foreach ([$this->guard, staff(Role::Accounting), employee()] as $who) {
        expect(ProbationEvaluation::canEvaluate($who, $this->profile))->toBeFalse();
    }
    expect(fn () => app(EvaluateProbation::class)->handle(staff(Role::Accounting), $this->profile, 'pass', 'Looks fine to me.'))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->opsHq)->test(EmployeeDetail::class, ['profile' => $this->profile])
        ->call('startEvaluation')->set('evaluationOutcome', 'fail')->set('evaluationReason', 'Too short.')
        ->call('evaluate')->assertHasErrors('evaluationReason');
});

it('asks the head as well as HR to evaluate, and stops once a decision is approved', function () {
    // Hired Apr 20 → last day Oct 20. Both it and Rico's (Nov 1) are inside the 30 days.
    $soon = employee(['employment_status' => 'probationary', 'hire_date' => '2026-04-20']);

    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 2 probation alerts.');
    Notification::assertSentTo([$this->hr, $this->opsHq], ProbationEnding::class);
    Notification::assertNotSentTo($soon, ProbationEnding::class);

    app(EvaluateProbation::class)->handle($this->hr, $soon->employeeProfile()->firstOrFail(), 'pass', 'Meets every standard.');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 07:00'));
    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 0 probation alerts.');
});

it('lets HR set the probation and training lengths, never more than 6 months', function () {
    Livewire::actingAs($this->hr)->test(Employees::class)
        ->assertSee('Probation & training rules')->assertSee('6 months')
        ->call('editRules')->set('probationMonths', '7')->call('saveRules')->assertHasErrors('probationMonths')
        ->set('probationMonths', '3')->set('trainingDays', '45')->call('saveRules')->assertHasNoErrors();

    expect(ProbationRules::months())->toBe(3)->and(ProbationRules::trainingDays())->toBe(45)
        ->and(ProbationRules::endsOn($this->profile->fresh())?->toDateString())->toBe('2026-08-01')
        ->and(ActivityLog::query()->where('event', 'probation.rules_changed')->count())->toBe(1)
        ->and(fn () => app(SaveProbationRules::class)->handle(staff(Role::Accounting), 6, 30, 30))->toThrow(AuthorizationException::class);

    Livewire::actingAs(staff(Role::Accounting))->test(Employees::class)->assertDontSee('Probation & training rules');
});

it('tells HR and the head when a Trainee\'s training is up, once, then weekly', function () {
    // Hired Sep 8 → 30 days on Oct 8.
    $trainee = employee(['employment_status' => 'trainee', 'hire_date' => '2026-09-08', 'name' => 'Ana Reyes']);

    // Training is part of the 6 months (Art. 296: from the first day of work), and the page says so.
    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['profile' => $trainee->employeeProfile()->firstOrFail()])
        ->assertSee('Training counts toward probation')->assertSee('Mar 8, 2027');

    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 1 training alert.');
    Notification::assertSentTo([$this->hr, $this->opsHq], UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Training ends today: Ana Reyes');

    $this->artisan('app:send-probation-alerts')->doesntExpectOutput('Sent 1 training alert.');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00'));
    $this->artisan('app:send-probation-alerts')->expectsOutput('Sent 1 training alert.');
});

it('offers only active statuses on the record; Resigned and Terminated come from processes', function () {
    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['profile' => $this->profile])
        ->call('edit')
        ->assertDontSee('<option value="terminated">', false)->assertDontSee('<option value="resigned">', false)
        ->set('form.employment_status', 'terminated')->call('save')->assertHasErrors('form.employment_status');

    // A record already Resigned keeps its status and can still be saved.
    $resigned = employee(['employment_status' => 'resigned', 'position' => Position::SecurityGuard])->employeeProfile()->firstOrFail();
    Livewire::actingAs($this->hr)->test(EmployeeDetail::class, ['profile' => $resigned])
        ->call('edit')->assertSee('<option value="resigned">', false)
        ->set('form.contact_number', '0917 000 0000')->call('save')->assertHasNoErrors();
});
