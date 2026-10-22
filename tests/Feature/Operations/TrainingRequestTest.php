<?php

use App\Actions\Training\DecideTrainingRequest;
use App\Actions\Training\EnrollTrainee;
use App\Actions\Training\FileTrainingRequest;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Enums\TrainingRequestKind;
use App\Livewire\Admin\Trainings;
use App\Livewire\Training\MyTraining;
use App\Models\ActivityLog;
use App\Models\Training;
use App\Models\TrainingRequest;
use App\Notifications\TrainingRequestNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// A guard asks for a training on My Training; Admin answers it on Training by
// adding them to a session, approving it for later, or declining with a reason.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
    $this->guard = employee(['detachment' => 'SM Megamall', 'post' => 'Lobby']);
    $this->admin = staff(Role::Admin);
    $this->refresher = Training::query()->create(['title' => 'Annual firearms refresher', 'starts_on' => '2026-11-03', 'venue' => 'Camp Crame']);
});

it('lets a guard ask for a training, and tells Admin', function () {
    Notification::fake();

    Livewire::actingAs($this->guard)->test(MyTraining::class)
        ->set('kind', TrainingRequestKind::LicenseRenewal->value)->set('preferredFrom', '2026-11-01')
        ->set('reason', 'My license expires in March and I need the refresher to renew it.')
        ->call('submit')->assertHasNoErrors()
        ->assertSee('Training for license renewal')->assertSee('Pending');

    $request = TrainingRequest::query()->sole();
    expect($request->user_id)->toBe($this->guard->id)
        ->and($request->status)->toBe(RequestStatus::Pending)
        ->and(ActivityLog::query()->where('event', 'training.requested')->exists())->toBeTrue();

    Notification::assertSentTo($this->admin, TrainingRequestNotice::class, fn (TrainingRequestNotice $notice) => $notice->event === TrainingRequestNotice::FILED);

    // The menu shows it to guards, and a badge to Admin.
    $labels = fn ($user) => collect(Navigation::for($user))->flatMap(fn (array $section) => $section['items']);
    expect($labels($this->guard)->pluck('label'))->toContain('My Training')
        ->and($labels($this->admin)->firstWhere('label', 'Training')['badge'] ?? null)->toBe(1);
});

it('keeps one open request per training, and only for guards', function () {
    $file = fn ($who) => app(FileTrainingRequest::class)->handle($who, TrainingRequestKind::FirstAid, 'For the new post.', null);

    $file($this->guard);
    expect(fn () => $file($this->guard))->toThrow(ValidationException::class, 'You already asked for First aid');

    // Office staff don't get the page at all.
    $this->actingAs(staff(Role::Accounting))->get(route('my-training'))->assertNotFound();
});

it('lets the guard cancel while pending', function () {
    $request = app(FileTrainingRequest::class)->handle($this->guard, TrainingRequestKind::FirstAid, 'For the new post.', null);

    Livewire::actingAs($this->guard)->test(MyTraining::class)->call('cancel', $request->id)->assertHasNoErrors();
    expect($request->fresh()?->status)->toBe(RequestStatus::Cancelled);
});

it('adds the guard to a session when Admin approves, and tells them', function () {
    Notification::fake();
    $request = app(FileTrainingRequest::class)->handle($this->guard, TrainingRequestKind::FirearmsRefresher, 'Due for my yearly refresher.', null);

    Livewire::actingAs($this->admin)->test(Trainings::class)
        ->assertSee('Training requests')->assertSee('Due for my yearly refresher.')
        ->call('answer', $request->id)
        ->set('sessionId', (string) $this->refresher->id)
        ->call('approveRequest')->assertHasNoErrors();

    expect($request->fresh())
        ->status->toBe(RequestStatus::Approved)
        ->training_id->toBe($this->refresher->id)
        ->and($this->refresher->attendees()->where('employee_profile_id', $this->guard->employeeProfile?->id)->exists())->toBeTrue();

    Notification::assertSentTo($this->guard, TrainingRequestNotice::class, fn (TrainingRequestNotice $notice) => $notice->toArray($this->guard)['title'] === 'Training: you\'re in Annual firearms refresher');

    Livewire::actingAs($this->guard)->test(MyTraining::class)
        ->assertSee('Added to Annual firearms refresher (Nov 3, 2026)')
        ->assertSee('Enrolled');
});

it('approves for later, and links the request when Admin adds them to a session', function () {
    Notification::fake();
    $request = app(FileTrainingRequest::class)->handle($this->guard, TrainingRequestKind::FirstAid, 'For the new post.', null);

    app(DecideTrainingRequest::class)->handle($this->admin, $request, RequestStatus::Approved, null, null);
    expect($request->fresh()?->isWaitingForSession())->toBeTrue()
        ->and($request->fresh()?->outcomeLabel())->toBe('Approved: waiting for a session');

    $firstAid = Training::query()->create(['title' => 'Basic first aid', 'starts_on' => '2026-11-10']);
    app(EnrollTrainee::class)->handle($firstAid, $this->guard->employeeProfile, $this->admin);

    expect($request->fresh()?->training_id)->toBe($firstAid->id);
    Notification::assertSentTo($this->guard, TrainingRequestNotice::class, fn (TrainingRequestNotice $notice) => $notice->event === TrainingRequestNotice::ADDED_TO_SESSION);
});

it('declines only with a reason, and not into a session that is over', function () {
    $request = app(FileTrainingRequest::class)->handle($this->guard, TrainingRequestKind::PreLicensing, 'I want to be licensed.', null);
    $page = Livewire::actingAs($this->admin)->test(Trainings::class)->call('answer', $request->id);

    $page->call('declineRequest')->assertHasErrors(['reviewNote']);

    $past = Training::query()->create(['title' => 'Old course', 'starts_on' => '2026-09-01']);
    $page->set('sessionId', (string) $past->id)->call('approveRequest')->assertHasErrors(['sessionId']);

    $page->set('sessionId', '')->set('reviewNote', 'You already hold a license; ask for the refresher instead.')->call('declineRequest')->assertHasNoErrors();
    expect($request->fresh())
        ->status->toBe(RequestStatus::Rejected)
        ->review_note->toBe('You already hold a license; ask for the refresher instead.');
});

it('is answered by Admin only', function () {
    $request = app(FileTrainingRequest::class)->handle($this->guard, TrainingRequestKind::FirstAid, 'For the new post.', null);

    expect(fn () => app(DecideTrainingRequest::class)->handle(staff(Role::Hr), $request, RequestStatus::Approved, null, null))
        ->toThrow(AuthorizationException::class);
    $this->actingAs(staff(Role::Hr))->get(route('trainings'))->assertForbidden();
});
