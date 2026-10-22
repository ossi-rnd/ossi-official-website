<?php

use App\Actions\Leave\AttachLeaveDocument;
use App\Actions\Leave\ReviewLeaveRequest;
use App\Actions\Leave\SaveLeaveRules;
use App\Actions\Leave\SubmitLeaveRequest;
use App\Enums\LeaveStage;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Leave\MyLeave;
use App\Models\ActivityLog;
use App\Models\LeaveRequest;
use App\Notifications\LeaveAwaitingYou;
use App\Support\LeaveRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Leave requirements (roadmap 24): the leaves under special laws, with what they
// give and the proof they need; a medical certificate for sick leave from 2 days;
// no approving without the proof; sick and emergency leave filed once back;
// documents seen only by the employee and whoever decides; VAWC kept confidential.

beforeEach(function () {
    Notification::fake();
    Storage::fake((string) config('leave.documents_disk'));
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));

    $this->clerk = employee(['is_office_staff' => true]);
    $this->executive = staff(Role::President);
    $this->hr = staff(Role::Hr);
    $this->opsHq = staff(Role::OperationsHq);
    $this->guard = employee();
});

function requestLeave(object $test, $who, LeaveType $type, string $start, string $end, array $documents = []): LeaveRequest
{
    return app(SubmitLeaveRequest::class)->handle($who, $type, CarbonImmutable::parse($start), CarbonImmutable::parse($end), 'As filed in the test.', $documents);
}

it('needs a medical certificate for sick leave from 2 days, before it can be approved', function () {
    $oneDay = requestLeave($this, $this->clerk, LeaveType::Sick, '2026-10-16', '2026-10-16');
    expect($oneDay->documentNeeded())->toBeNull();
    app(ReviewLeaveRequest::class)->handle($oneDay, $this->executive, RequestStatus::Approved, null);

    $threeDays = requestLeave($this, $this->clerk, LeaveType::Sick, '2026-10-20', '2026-10-22');
    expect($threeDays->documentNeeded())->toBe('A medical certificate');
    expect(fn () => app(ReviewLeaveRequest::class)->handle($threeDays, $this->executive, RequestStatus::Approved, null))
        ->toThrow(ValidationException::class, 'Waiting for the document: a medical certificate');

    // Attached after filing: whoever decides is told again, then it can be approved.
    Notification::fake();
    app(AttachLeaveDocument::class)->handle($this->clerk, $threeDays, UploadedFile::fake()->image('certificate.jpg'));
    Notification::assertSentTo($this->executive, LeaveAwaitingYou::class);
    app(ReviewLeaveRequest::class)->handle($threeDays->fresh(), $this->executive, RequestStatus::Approved, null);
    expect($threeDays->fresh()?->status)->toBe(RequestStatus::Approved);
});

it('lets sick and emergency leave be filed once back, up to 7 days, but nothing else in the past', function () {
    expect(requestLeave($this, $this->clerk, LeaveType::Sick, '2026-10-14', '2026-10-14')->status)->toBe(RequestStatus::Pending);
    expect(fn () => requestLeave($this, $this->clerk, LeaveType::Sick, '2026-10-05', '2026-10-05'))->toThrow(ValidationException::class, 'up to 7 days after');
    expect(fn () => requestLeave($this, $this->clerk, LeaveType::Vacation, '2026-10-16', '2026-10-16'))->toThrow(ValidationException::class, 'can\'t be in the past');
});

it('shows what the law gives and the proof needed, and flags a longer request to the approver', function () {
    Livewire::actingAs($this->clerk)->test(MyLeave::class)
        ->set('type', 'paternity')
        ->assertSee('7 days with pay, for each of the first four deliveries')
        ->assertSee('marriage certificate');

    $long = requestLeave($this, $this->clerk, LeaveType::Paternity, '2026-10-20', '2026-10-28', [UploadedFile::fake()->image('birth-certificate.jpg')]);
    expect($long->exceedsEntitlement())->toBeTrue()
        ->and($long->documents)->toHaveCount(1);

    Livewire::actingAs($this->executive)->test(LeaveRequests::class)
        ->call('startReview', $long->id)
        ->assertSee('This asks for more (9 days).')
        ->assertSee('birth-certificate.jpg');
});

it('shows the documents only to the employee and whoever decides, auditing the decider\'s views', function () {
    $leave = requestLeave($this, $this->guard, LeaveType::SoloParent, '2026-10-20', '2026-10-21', [UploadedFile::fake()->image('solo-parent-id.jpg')]);
    $document = $leave->documents->sole();

    $this->actingAs($this->guard)->get(route('leave.document', $document))->assertOk();
    $this->actingAs($this->hr)->get(route('leave.document', $document))->assertOk();
    expect(ActivityLog::query()->where('event', 'leave.document_viewed')->count())->toBe(1);

    // Operation HQ endorses guards' leave, but doesn't open their papers.
    $this->actingAs($this->opsHq)->get(route('leave.document', $document))->assertForbidden();
    $this->actingAs(employee())->get(route('leave.document', $document))->assertForbidden();
});

it('keeps VAWC leave confidential: straight to HR, hidden from the endorsers, no details in the email', function () {
    $leave = requestLeave($this, $this->guard, LeaveType::Vawc, '2026-10-20', '2026-10-29');
    expect($leave->stage)->toBe(LeaveStage::Hr);

    expect(LeaveRequest::query()->visibleTo($this->hr)->pluck('id')->all())->toContain($leave->id)
        ->and(LeaveRequest::query()->visibleTo($this->opsHq)->pluck('id')->all())->not->toContain($leave->id);

    Notification::assertSentTo($this->hr, LeaveAwaitingYou::class, fn (LeaveAwaitingYou $notice) => str_starts_with($notice->toArray($this->hr)['body'], 'Confidential leave')
        && ! str_contains($notice->toArray($this->hr)['body'], 'As filed in the test.'));
    Notification::assertNotSentTo($this->opsHq, LeaveAwaitingYou::class);
});

it('lets HR set the leave rules in the app, audited, and My Leave follows', function () {
    app(SaveLeaveRules::class)->handle($this->hr, 3, 0);
    expect(LeaveRules::all())->toBe(['sick_certificate_from_days' => 3, 'file_after_days' => 0])
        ->and(ActivityLog::query()->where('event', 'leave.rules_changed')->count())->toBe(1);

    // 2 days of sick leave no longer needs a certificate; filing after the fact is off.
    expect(requestLeave($this, $this->clerk, LeaveType::Sick, '2026-10-20', '2026-10-21')->documentNeeded())->toBeNull();
    expect(fn () => requestLeave($this, $this->clerk, LeaveType::Sick, '2026-10-16', '2026-10-16'))->toThrow(ValidationException::class, 'up to 0 days after');
    Livewire::actingAs($this->clerk)->test(MyLeave::class)->set('type', 'sick')->assertSee('3 days or more:');

    // HR changes them on Leave Requests; the Executive sees them but can't.
    Livewire::actingAs($this->hr)->test(LeaveRequests::class)->call('editRules')->set('fileAfterDays', '5')->call('saveRules')->assertHasNoErrors();
    expect(LeaveRules::fileAfterDays())->toBe(5);
    Livewire::actingAs($this->executive)->test(LeaveRequests::class)->assertSee('5 days after')->call('editRules')->assertForbidden();
    expect(fn () => app(SaveLeaveRules::class)->handle($this->executive, 2, 7))->toThrow(AuthorizationException::class);
});
