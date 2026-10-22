<?php

use App\Actions\Complaints\FileComplaint;
use App\Actions\Complaints\SaveComplaintSubject;
use App\Enums\ComplaintCategory;
use App\Enums\Role;
use App\Livewire\Admin\Complaints;
use App\Livewire\Complaints\MyComplaints;
use App\Models\ActivityLog;
use App\Models\Complaint;
use App\Models\ComplaintSubject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Each category has common subjects from HR's list (a starting list HR can change);
// the employee picks one or "Other" and types it. A complaint copies the words.

beforeEach(function () {
    $this->guard = employee();
    $this->hr = staff(Role::Hr);
    $this->overtime = ComplaintSubject::query()->where('label', 'Overtime not paid')->sole();
});

it('offers the category\'s subjects, and copies the one picked onto the complaint', function () {
    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->set('category', ComplaintCategory::PayAndBenefits->value)
        ->assertSee('Overtime not paid')->assertSee('Other (type it)')->assertDontSee('No relief guard')
        ->set('subjectId', (string) $this->overtime->id)
        ->set('details', 'My 4 hours of overtime on Oct 10 were not on my payslip.')->set('acknowledges', true)
        ->call('submit')->assertHasNoErrors();

    $complaint = Complaint::query()->sole();
    expect($complaint->subject)->toBe('Overtime not paid')
        ->and($complaint->complaint_subject_id)->toBe($this->overtime->id);

    // Renaming it later doesn't rewrite the complaint.
    app(SaveComplaintSubject::class)->handle($this->hr, $this->overtime, ComplaintCategory::PayAndBenefits, 'Overtime or night differential not paid');
    expect($complaint->fresh()?->subject)->toBe('Overtime not paid');
});

it('takes a typed subject with "Other", and asks for one when nothing is picked', function () {
    $page = Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->set('category', ComplaintCategory::Safety->value)
        ->set('details', 'The guardhouse roof leaks onto the logbook when it rains.')->set('acknowledges', true);

    $page->call('submit')->assertHasErrors(['subjectId']);
    $page->set('subjectId', 'other')->call('submit')->assertHasErrors(['subject']);
    $page->set('subjectId', 'other')->set('subject', 'Leaking guardhouse roof')->call('submit')->assertHasNoErrors();

    expect(Complaint::query()->sole())->subject->toBe('Leaking guardhouse roof')->complaint_subject_id->toBeNull();
});

it('starts over on the subject when the category changes, and types it for "Other" with no list', function () {
    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->set('category', ComplaintCategory::PayAndBenefits->value)->set('subjectId', (string) $this->overtime->id)
        ->set('category', ComplaintCategory::Other->value)
        ->assertSet('subjectId', '')
        ->assertDontSee('Other (type it)')->assertSee('One line, e.g.');
});

it('refuses a subject from another category or switched off', function () {
    $file = fn (ComplaintSubject $subject) => app(FileComplaint::class)->handle($this->guard, ComplaintCategory::Safety, '', 'Something happened at the post that I want HR to know.', null, null, picked: $subject);

    expect(fn () => $file($this->overtime))->toThrow(ValidationException::class, 'Choose a subject from the list');

    $noRelief = ComplaintSubject::query()->where('label', 'No relief guard')->sole();
    app(SaveComplaintSubject::class)->handle($this->hr, $noRelief, ComplaintCategory::Safety, $noRelief->label, active: false);
    expect(fn () => $file($noRelief->fresh()))->toThrow(ValidationException::class, 'Choose a subject from the list');

    Livewire::actingAs($this->guard)->test(MyComplaints::class)->set('category', ComplaintCategory::Safety->value)->assertDontSee('No relief guard');
});

it('lets HR add, rename and switch off subjects, audited, and see how often each came up', function () {
    app(FileComplaint::class)->handle($this->guard, ComplaintCategory::PayAndBenefits, '', 'My overtime on Oct 10 was not paid.', null, null, picked: $this->overtime);

    $page = Livewire::actingAs($this->hr)->test(Complaints::class)
        ->call('openSubjects')
        ->assertSee('Overtime not paid')
        ->set('subjectCategory', ComplaintCategory::PayAndBenefits->value)->set('subjectLabel', 'Night differential not paid')
        ->call('saveSubject')->assertHasNoErrors()
        ->assertSee('Night differential not paid');

    expect($page->instance()->subjectList->firstWhere('id', $this->overtime->id)?->recent_count)->toBe(1);

    // No duplicates in a category.
    $page->set('subjectCategory', ComplaintCategory::PayAndBenefits->value)->set('subjectLabel', 'overtime NOT paid')->call('saveSubject')->assertHasErrors(['subjectLabel']);

    $page->call('editSubject', $this->overtime->id)->set('subjectLabel', 'Overtime unpaid')->call('saveSubject')->assertHasNoErrors();
    $page->call('toggleSubject', $this->overtime->id);
    expect($this->overtime->fresh())->label->toBe('Overtime unpaid')->is_active->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'complaint_subject.created')->exists())->toBeTrue();

    expect(fn () => app(SaveComplaintSubject::class)->handle(staff(Role::Admin), null, ComplaintCategory::Safety, 'Anything'))
        ->toThrow(AuthorizationException::class);
});
