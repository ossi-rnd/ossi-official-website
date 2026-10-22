<?php

use App\Actions\Complaints\FileComplaint;
use App\Actions\Complaints\SaveComplaintRules;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Livewire\Admin\Complaints;
use App\Livewire\Complaints\MyComplaints;
use App\Models\ActivityLog;
use App\Models\Complaint;
use App\Notifications\ComplaintNotice;
use App\Support\ComplaintRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// "I need help now" alerts HR at once (bell and email, still without the contents)
// and puts the case first. The weekly limit is HR's number (Complaints → Rules),
// and never applies to asking for help, harassment, or resignation cases.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00'));
    $this->guard = employee();
    $this->hr = staff(Role::Hr);
});

function complain($guard, ComplaintCategory $category = ComplaintCategory::Conduct, bool $help = false): Complaint
{
    return app(FileComplaint::class)->handle($guard, $category, 'Something happened', 'The shift supervisor shouted at me in front of the client.', null, null, asksForHelp: $help);
}

it('alerts HR by bell and email when someone asks for help, without the contents', function () {
    Notification::fake();

    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->set('asksForHelp', true)->assertSee('Call 911 first')
        ->set('subjectId', 'other')->set('subject', 'Threatened at the post')->set('details', 'A man at the gate said he would hurt me if I report him again.')
        ->set('acknowledges', true)
        ->call('submit')->assertHasNoErrors();

    $complaint = Complaint::query()->sole();
    expect($complaint->asks_for_help)->toBeTrue();

    Notification::assertSentTo($this->hr, ComplaintNotice::class, function (ComplaintNotice $notice) {
        $mail = $notice->toMail($this->hr);
        $text = $mail->subject.' '.implode(' ', $mail->introLines);

        return $notice->via($this->hr) === ['database', 'mail']
            && str_starts_with($mail->subject, 'Urgent:')
            && ! str_contains($text, 'hurt me') && ! str_contains($text, 'Threatened'); // never the contents
    });
});

it('keeps an ordinary complaint bell only', function () {
    Notification::fake();
    complain($this->guard);

    Notification::assertSentTo($this->hr, ComplaintNotice::class, fn (ComplaintNotice $notice) => $notice->via($this->hr) === ['database']);
});

it('puts help requests first for HR, flagged, until the case is closed', function () {
    $older = complain($this->guard);
    $this->travel(1)->hours();
    $help = complain(employee(), help: true);
    $this->travel(1)->hours();
    complain(employee());

    $page = Livewire::actingAs($this->hr)->test(Complaints::class);
    expect($page->instance()->complaints->first()?->id)->toBe($help->id);
    $page->assertSee('Asks for help')
        ->call('open', $help->id)->assertSee('asked for help now');

    $help->forceFill(['status' => ComplaintStatus::Resolved, 'resolution' => 'Moved them to another post the same day.'])->save();
    expect($help->fresh()?->needsHelpNow())->toBeFalse()
        ->and($older->needsHelpNow())->toBeFalse();
});

it('limits ordinary complaints to HR\'s number a week, with when the next one is possible', function () {
    $first = complain($this->guard);

    expect(fn () => complain($this->guard))->toThrow(ValidationException::class, "You filed {$first->reference()} on Oct 20. You can file another from Oct 27");

    // Seven days later, it's possible again.
    $this->travelTo(CarbonImmutable::parse('2026-10-27 09:01'));
    complain($this->guard);
    expect(Complaint::query()->where('user_id', $this->guard->id)->count())->toBe(2);
});

it('never limits asking for help, harassment, or a resignation\'s complaint', function () {
    complain($this->guard);

    complain($this->guard, help: true);
    complain($this->guard, ComplaintCategory::Harassment);
    app(FileComplaint::class)->handle($this->guard, ComplaintCategory::Conduct, 'Just cause given for resigning: inhuman treatment', 'My letter.', null, null, exemptFromLimit: true);

    expect(Complaint::query()->where('user_id', $this->guard->id)->count())->toBe(4);
});

it('shows the allowance before writing, like a token balance, and the limit as a message', function () {
    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->assertSee('You can file 1 complaint this week');

    complain($this->guard);
    expect(ComplaintRules::allowanceFor($this->guard))->left->toBe(0)
        ->and(ComplaintRules::allowanceFor($this->guard)['nextAt']?->format('M j, g:i A'))->toBe('Oct 27, 9:00 AM');

    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->assertSee('You\'ve used this week\'s complaint.', escape: false)->assertSee('Next one possible Oct 27, 9:00 AM')
        ->set('subjectId', 'other')->set('subject', 'Again')->set('details', 'Another thing that happened at the post today.')->set('acknowledges', true)
        ->call('submit')->assertHasErrors(['limit']);

    // Help requests and harassment don't use it up.
    complain($this->guard, ComplaintCategory::Harassment);
    complain($this->guard, help: true);
    expect(ComplaintRules::allowanceFor($this->guard)['used'])->toHaveCount(1);

    // No limit, no counter.
    app(SaveComplaintRules::class)->handle($this->hr, 0);
    Livewire::actingAs($this->guard)->test(MyComplaints::class)->assertDontSee('this week');
});

it('lets HR change the limit in the app, audited, and 0 removes it', function () {
    Livewire::actingAs($this->hr)->test(Complaints::class)
        ->assertSee('1 in 7 days')
        ->call('editRules')->set('perWeek', '11')->call('saveRules')->assertHasErrors(['perWeek'])
        ->set('perWeek', '2')->call('saveRules')->assertHasNoErrors()
        ->assertSee('2 in 7 days');

    expect(ComplaintRules::perWeek())->toBe(2)
        ->and(ActivityLog::query()->where('event', 'complaint.rules_changed')->exists())->toBeTrue();

    complain($this->guard);
    complain($this->guard);
    expect(fn () => complain($this->guard))->toThrow(ValidationException::class, 'You filed 2 complaints in the last 7 days');

    app(SaveComplaintRules::class)->handle($this->hr, 0);
    complain($this->guard);
    expect(Complaint::query()->where('user_id', $this->guard->id)->count())->toBe(3);

    // Only HR sets it.
    expect(fn () => app(SaveComplaintRules::class)->handle(staff(Role::Admin), 3))->toThrow(AuthorizationException::class);
});
