<?php

use App\Actions\People\AcknowledgeNotice;
use App\Actions\People\DecideNotice;
use App\Actions\People\ExplainNotice;
use App\Actions\People\IssueNoticeToExplain;
use App\Actions\People\ScheduleNoticeHearing;
use App\Enums\EmploymentStatus;
use App\Enums\NteOutcome;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\NoticesToExplain;
use App\Livewire\Notices\MyNotices;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\MeritType;
use App\Models\NoticeToExplain;
use App\Notifications\NoticeToExplainNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Notices to Explain (roadmap 30): the twin-notice rule. The specific acts, the
// rule, the possible penalty and at least 5 days to explain; the employee's side
// (and a hearing if asked); then the decision with its reasons. Never decided
// before the employee has had their chance.

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));

    $makati = Client::query()->create(['region' => Region::Ncr, 'province' => 'Makati', 'name' => 'Makati CBD']);
    $imus = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->guard = employee(['client_id' => $makati->id]);
    $this->cavite = employee(['client_id' => $imus->id]);
    $this->opsHq = staff(Role::OperationsHq);
    $this->satellite = staff(Role::SatelliteOperations);
    $this->satellite->forceFill(['operations_region' => Region::Ncr])->save();
    $this->hr = staff(Role::Hr);
    $this->otherHr = staff(Role::Hr);
    $this->executive = staff(Role::President);
    $this->accountant = staff(Role::Accounting);
    $this->adminStaff = staff(Role::Admin);
});

function issued(object $test, ?CarbonImmutable $explainBy = null): NoticeToExplain
{
    return app(IssueNoticeToExplain::class)->handle(
        $test->opsHq,
        $test->guard,
        'Sleeping on post',
        CarbonImmutable::parse('2026-10-14'),
        'On Oct 14 at about 2:30 AM the roving supervisor found you asleep in the guard house at Gate 2.',
        'Code of Conduct, Section 4.2: Sleeping while on duty.',
        NteOutcome::Suspension,
        $explainBy ?? CarbonImmutable::parse('2026-10-21'),
    );
}

it('is issued by whoever supervises the person: Operations for guards, HR for Admin, the President or VP for HR and Accounting', function () {
    expect(NoticeToExplain::canIssue($this->opsHq, $this->guard))->toBeTrue()
        ->and(NoticeToExplain::canIssue($this->satellite, $this->guard))->toBeTrue()
        ->and(NoticeToExplain::canIssue($this->satellite, $this->cavite))->toBeFalse()
        ->and(NoticeToExplain::canIssue($this->hr, $this->guard))->toBeFalse() // guards: Operations
        ->and(NoticeToExplain::canIssue($this->hr, $this->adminStaff))->toBeTrue()
        ->and(NoticeToExplain::canIssue($this->hr, $this->accountant))->toBeFalse()
        ->and(NoticeToExplain::canIssue($this->executive, $this->accountant))->toBeTrue()
        ->and(NoticeToExplain::canIssue($this->hr, $this->otherHr))->toBeFalse()
        ->and(NoticeToExplain::canIssue($this->executive, $this->otherHr))->toBeTrue()
        ->and(NoticeToExplain::canIssue($this->accountant, $this->guard))->toBeFalse();

    // HR still reads guards' notices; the Executive reads all.
    expect(NoticeToExplain::canView($this->hr, $this->guard))->toBeTrue()
        ->and(NoticeToExplain::canView($this->hr, $this->otherHr))->toBeFalse()
        ->and(NoticeToExplain::canView($this->accountant, $this->guard))->toBeFalse();
});

it('needs the specific acts, the rule, the penalty and at least 5 calendar days, and tells the employee', function () {
    expect(fn () => app(IssueNoticeToExplain::class)->handle($this->opsHq, $this->guard, 'Sleeping on post', CarbonImmutable::today(), 'Asleep.', 'Rule 4.2', NteOutcome::Suspension, CarbonImmutable::parse('2026-10-21')))
        ->toThrow(ValidationException::class, 'specific acts');
    expect(fn () => issued($this, CarbonImmutable::parse('2026-10-19')))->toThrow(ValidationException::class, 'at least 5 calendar days');

    $notice = issued($this);
    expect($notice->status)->toBe(NoticeToExplain::ISSUED)->and($notice->stage())->toBe('Waiting for the explanation (until Oct 21)');
    Notification::assertSentTo($this->guard, NoticeToExplainNotice::class, fn (NoticeToExplainNotice $n) => $n->event === NoticeToExplainNotice::ISSUED
        && str_contains($n->toArray($this->guard)['body'], 'by Oct 21, 2026'));
});

it('takes the employee\'s explanation once, within the days given, and counts it as received', function () {
    $notice = issued($this);

    expect(fn () => app(ExplainNotice::class)->handle($this->cavite, $notice, 'Not my notice, but here is a long explanation.', false))->toThrow(AuthorizationException::class);
    app(ExplainNotice::class)->handle($this->guard, $notice, 'I felt dizzy from medicine and sat down; I did not mean to fall asleep. Sgt. Reyes knows.', true);

    $notice->refresh();
    expect($notice)->status->toBe(NoticeToExplain::EXPLAINED)->wants_hearing->toBeTrue()->acknowledged_at->not->toBeNull();
    Notification::assertSentTo($this->opsHq, NoticeToExplainNotice::class, fn (NoticeToExplainNotice $n) => $n->event === NoticeToExplainNotice::EXPLAINED);
    expect(fn () => app(ExplainNotice::class)->handle($this->guard, $notice, 'Another explanation that is long enough.', false))->toThrow(ValidationException::class, 'already explained');
});

it('is never decided before the employee has had their chance', function () {
    $notice = issued($this);
    $reason = 'He was found asleep at 2:30 AM; the explanation does not excuse it.';

    expect(fn () => app(DecideNotice::class)->handle($this->opsHq, $notice, NteOutcome::Reprimand, $reason))->toThrow(ValidationException::class, 'chance to explain first');

    // The days pass with no explanation: now it can be decided.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00'));
    expect($notice->fresh()?->stage())->toBe('No explanation · to decide');
    $decided = app(DecideNotice::class)->handle($this->opsHq, $notice->fresh(), NteOutcome::Reprimand, $reason);
    expect($decided->status)->toBe(NoticeToExplain::DECIDED)->and($decided->decision)->toBe(NteOutcome::Reprimand);
});

it('decides a suspension with its days, can add a demerit, and tells HR of a dismissal', function () {
    $notice = issued($this);
    app(ExplainNotice::class)->handle($this->guard, $notice, 'I felt dizzy from medicine and sat down; I did not mean to fall asleep.', true);
    app(ScheduleNoticeHearing::class)->handle($this->opsHq, $notice->fresh(), CarbonImmutable::parse('2026-10-20 10:00'), 'HQ conference room');
    Notification::assertSentTo($this->guard, NoticeToExplainNotice::class, fn (NoticeToExplainNotice $n) => $n->event === NoticeToExplainNotice::HEARING);

    expect(fn () => app(DecideNotice::class)->handle($this->opsHq, $notice->fresh(), NteOutcome::Suspension, 'Confirmed at the hearing: asleep at 2:30 AM, a second offence.'))
        ->toThrow(ValidationException::class, '1 to 30');

    $sleeping = MeritType::query()->where('name', 'Sleeping on post')->sole();
    $decided = app(DecideNotice::class)->handle($this->opsHq, $notice->fresh(), NteOutcome::Suspension, 'Confirmed at the hearing: asleep at 2:30 AM, a second offence.', 3, CarbonImmutable::parse('2026-10-23'), $sleeping);
    expect($decided)->suspension_days->toBe(3)
        ->and($decided->demerit)->points->toBe(-5)->user_id->toBe($this->guard->id);
    Notification::assertSentTo($this->guard, NoticeToExplainNotice::class, fn (NoticeToExplainNotice $n) => $n->event === NoticeToExplainNotice::DECIDED
        && str_contains($n->toArray($this->guard)['body'], '3 days from Oct 23'));

    app(AcknowledgeNotice::class)->handle($this->guard, $decided);
    expect($decided->fresh()?->decision_acknowledged_at)->not->toBeNull();

    // A dismissal: the record becomes Terminated (never picked by hand), and HR is told.
    $second = app(IssueNoticeToExplain::class)->handle($this->executive, $this->accountant, 'Falsified receipts', CarbonImmutable::today(), 'Submitted three receipts on Oct 10 that the supplier says it never issued.', 'Code of Conduct, Section 9: Dishonesty.', NteOutcome::Dismissal, CarbonImmutable::parse('2026-10-21'));
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00'));
    app(DecideNotice::class)->handle($this->executive, $second, NteOutcome::Dismissal, 'No explanation given; the supplier confirmed in writing that the receipts are false.');
    Notification::assertSentTo([$this->hr, $this->otherHr], NoticeToExplainNotice::class, fn (NoticeToExplainNotice $n) => $n->event === NoticeToExplainNotice::DISMISSAL_HR);
    expect($this->accountant->employeeProfile()->firstOrFail()->employment_status)->toBe(EmploymentStatus::Terminated)
        ->and(ActivityLog::query()->where('event', 'nte.terminated')->count())->toBe(1);
});

it('works from the pages: issued by Operations, explained by the guard on My Notices', function () {
    Livewire::actingAs($this->opsHq)->test(NoticesToExplain::class, ['issueTo' => $this->guard->id])
        ->assertSet('issuing', true)
        ->set('subject', 'Sleeping on post')
        ->set('details', 'On Oct 14 at about 2:30 AM the roving supervisor found you asleep at Gate 2.')
        ->set('rule', 'Code of Conduct, Section 4.2')
        ->set('possiblePenalty', 'suspension')
        ->call('issue')
        ->assertHasNoErrors();

    $notice = NoticeToExplain::query()->sole();
    $myNotices = collect(Navigation::for($this->guard))->flatMap(fn (array $section) => $section['items'])->firstWhere('route', 'my-notices');
    expect($myNotices['badge'] ?? null)->toBe(1);

    Livewire::actingAs($this->guard)->test(MyNotices::class)
        ->assertSee('Sleeping on post')
        ->call('acknowledge', $notice->id)
        ->call('startExplaining', $notice->id)
        ->set('explanation', 'I felt dizzy from medicine and sat down; I did not mean to fall asleep.')
        ->call('explain')
        ->assertHasNoErrors()
        ->assertSee('Your explanation');

    $this->actingAs($this->guard)->get(route('notices-to-explain'))->assertForbidden();
    $this->actingAs($this->accountant)->get(route('notices-to-explain'))->assertForbidden();
    $this->actingAs($this->guard)->get(route('my-notices'))->assertOk();
});
