<?php

use App\Actions\Complaints\UpdateComplaint;
use App\Actions\Documents\SendDocumentExpiryAlerts;
use App\Enums\ComplaintStatus;
use App\Enums\DocumentCategory;
use App\Enums\Role;
use App\Enums\TrainingAttendance;
use App\Livewire\Admin\CompanyDocuments;
use App\Livewire\Admin\Complaints;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Trainings;
use App\Livewire\CompanyPolicies;
use App\Livewire\Complaints\MyComplaints;
use App\Models\ActivityLog;
use App\Models\CompanyDocument;
use App\Models\CompanyPolicy;
use App\Models\Complaint;
use App\Models\Training;
use App\Notifications\ComplaintNotice;
use App\Notifications\DocumentDueNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function libraryPdf(string $name = 'license.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% test\n%%EOF\n");
}

beforeEach(function () {
    Storage::fake((string) config('documents.disk'));
});

describe('documents and licenses', function () {
    it('lets Admin upload a license with its due date, and requires the date', function () {
        $page = Livewire::actingAs(staff(Role::Admin))->test(CompanyDocuments::class)
            ->call('selectTab', DocumentCategory::License->value)
            ->call('create')
            ->set('title', 'PNP-SOSIA License to Operate')
            ->set('file', libraryPdf())
            ->call('save')
            ->assertHasErrors('expiresOn');

        $page->set('expiresOn', today()->addDays(20)->toDateString())->call('save')->assertHasNoErrors()
            ->assertSee('PNP-SOSIA License to Operate')
            ->assertSee('Due in 20 days');

        $document = CompanyDocument::query()->sole();
        Storage::disk((string) config('documents.disk'))->assertExists($document->file_path);
        expect($document->category)->toBe(DocumentCategory::License);
    });

    it('keeps the old copy when a license is renewed', function () {
        $page = Livewire::actingAs(staff(Role::Admin))->test(CompanyDocuments::class)
            ->call('selectTab', 'license')->call('create')
            ->set('title', 'Business permit')->set('expiresOn', today()->addDays(5)->toDateString())->set('file', libraryPdf())
            ->call('save')->assertHasNoErrors();

        $old = CompanyDocument::query()->sole();

        $page->call('renew', $old->id)
            ->set('expiresOn', today()->addYear()->toDateString())->set('file', libraryPdf('renewed.pdf'))
            ->call('save')->assertHasNoErrors();

        $current = CompanyDocument::query()->current()->sole();
        expect($old->fresh()?->replaced_by_id)->toBe($current->id)
            ->and($current->title)->toBe('Business permit')
            ->and($current->expires_on?->isSameDay(today()->addYear()))->toBeTrue();
    });

    it('alerts Admin once at the nearest threshold before the due date', function () {
        Notification::fake();
        $admin = staff(Role::Admin);
        $document = new CompanyDocument;
        $document->forceFill(['category' => DocumentCategory::License, 'title' => 'LTO', 'expires_on' => today()->addDays(25)->toDateString(), 'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_mime' => 'application/pdf', 'file_size' => 10])->save();

        expect(app(SendDocumentExpiryAlerts::class)->handle(CarbonImmutable::today()))->toBe(1)
            ->and(app(SendDocumentExpiryAlerts::class)->handle(CarbonImmutable::today()))->toBe(0);

        Notification::assertSentTo($admin, DocumentDueNotice::class, fn (DocumentDueNotice $notice) => $notice->email && $notice->daysLeft === 25);
    });

    it('keeps documents to Admin', function () {
        $this->actingAs(staff(Role::Hr))->get(route('documents'))->assertForbidden();
        $this->actingAs(staff(Role::Admin))->get(route('documents'))->assertOk();
    });
});

describe('training', function () {
    it('records who completed a training, shown on their employee record', function () {
        $guard = employee();
        $admin = staff(Role::Admin);

        $page = Livewire::actingAs($admin)->test(Trainings::class)
            ->call('create')
            ->set('title', 'Annual firearms refresher')->set('startsOn', today()->subDay()->toDateString())
            ->call('save')->assertHasNoErrors();

        $training = Training::query()->sole();

        $page->call('manage', $training->id)
            ->set('enrollProfileId', (string) $guard->employeeProfile?->id)->call('enroll')->assertHasNoErrors()
            ->set('enrollProfileId', (string) $guard->employeeProfile?->id)->call('enroll')->assertHasErrors('enrollProfileId');

        $attendee = $training->attendees()->sole();
        $page->call('mark', $attendee->id, TrainingAttendance::Completed->value);

        expect($attendee->fresh()?->status)->toBe(TrainingAttendance::Completed);

        // Completed by someone: it stays on their record.
        $page->call('delete', $training->id)->assertHasErrors('training');

        Livewire::actingAs(staff(Role::Hr))->test(EmployeeDetail::class, ['profile' => $guard->employeeProfile])
            ->assertSee('Annual firearms refresher')->assertSee('Completed');
    });

    it('cannot mark a training that hasn\'t started', function () {
        $page = Livewire::actingAs(staff(Role::Admin))->test(Trainings::class)
            ->call('create')->set('title', 'First aid')->set('startsOn', today()->addWeek()->toDateString())->call('save');

        $training = Training::query()->sole();
        $page->call('manage', $training->id)->set('enrollProfileId', (string) employee()->employeeProfile?->id)->call('enroll');

        $page->call('mark', $training->attendees()->sole()->id, 'completed')->assertHasErrors('attendance');
    });
});

describe('complaints', function () {
    it('goes to HR only, and the employee sees the outcome', function () {
        Notification::fake();
        $guard = employee();
        $hr = staff(Role::Hr);

        Livewire::actingAs($guard)->test(MyComplaints::class)
            ->set('category', 'safety')->set('acknowledges', true)
            ->set('subjectId', 'other')->set('subject', 'Broken guardhouse lock')
            ->set('details', 'The guardhouse door has not locked for a month now.')
            ->call('submit')
            ->assertHasNoErrors();

        $complaint = Complaint::query()->sole();
        Notification::assertSentTo($hr, ComplaintNotice::class, fn (ComplaintNotice $notice) => $notice->forHr);

        // The audit log says it was filed, not what it says.
        expect(json_encode(ActivityLog::query()->where('event', 'complaint.filed')->sole()->toArray()))->not->toContain('guardhouse');

        $this->actingAs(staff(Role::Admin))->get(route('complaint-cases'))->assertForbidden();

        Livewire::actingAs($hr)->test(Complaints::class)
            ->call('open', $complaint->id)
            ->set('status', ComplaintStatus::Resolved->value)
            ->call('save')->assertHasErrors('resolution')
            ->set('resolution', 'Facilities replaced the lock on Oct 12. Thank you for reporting it.')
            ->call('save')->assertHasNoErrors();

        expect($complaint->fresh())->status->toBe(ComplaintStatus::Resolved)->closed_at->not->toBeNull();
        Notification::assertSentTo($guard, ComplaintNotice::class, fn (ComplaintNotice $notice) => ! $notice->forHr);

        Livewire::actingAs($guard)->test(MyComplaints::class)->assertSee('Facilities replaced the lock');

        // They acknowledged it's true, that the person involved may be told, and no retaliation: kept word for word.
        expect(Complaint::query()->where('subject', 'Broken guardhouse lock')->sole()->acknowledgement)->toBe(Complaint::acknowledgementLines('HR'));
        Livewire::actingAs($guard)->test(MyComplaints::class)->set('subjectId', 'other')->set('subject', 'Another one')->set('details', 'Something else happened at the post today.')
            ->call('submit')->assertHasErrors(['acknowledges' => 'accepted']);
        Livewire::actingAs(employee())->test(MyComplaints::class)->assertDontSee('Broken guardhouse lock');

        // The Executive doesn't see a guard's complaint: that's HR's.
        Livewire::actingAs(staff(Role::President))->test(Complaints::class)->assertDontSee('Broken guardhouse lock')
            ->call('open', $complaint->id)->assertNotFound();
    });

    it('sends a complaint filed by HR staff to the Executive only, never to HR', function () {
        Notification::fake();
        $hrClerk = staff(Role::Hr);
        $otherHr = staff(Role::Hr);
        $executive = staff(Role::President);

        Livewire::actingAs($hrClerk)->test(MyComplaints::class)
            ->assertSee('Goes to the President or Vice President only')->assertSee('Send to the President or Vice President')
            ->set('category', 'conduct')->set('acknowledges', true)
            ->set('subjectId', 'other')->set('subject', 'Unfair shift assignments in HR')
            ->set('details', 'I have been given every weekend shift for two months now.')
            ->call('submit')->assertHasNoErrors();

        $complaint = Complaint::query()->sole();
        expect($complaint->for_executive)->toBeTrue();
        Notification::assertSentTo($executive, ComplaintNotice::class, fn (ComplaintNotice $notice) => $notice->forHr);
        Notification::assertNotSentTo($otherHr, ComplaintNotice::class);

        // Other HR staff can't see it, open it or change it.
        Livewire::actingAs($otherHr)->test(Complaints::class)->assertDontSee('Unfair shift assignments')
            ->call('open', $complaint->id)->assertNotFound();
        expect(fn () => app(UpdateComplaint::class)->handle($complaint, ComplaintStatus::Investigating, null, $otherHr))->toThrow(AuthorizationException::class);
        expect(collect(Navigation::for($otherHr))->pluck('items')->flatten(1)->firstWhere('route', 'complaint-cases')['badge'])->toBe(0);

        // The Executive sees it in the menu (with its badge) and handles it.
        expect(collect(Navigation::for($executive))->pluck('items')->flatten(1)->firstWhere('route', 'complaint-cases')['badge'])->toBe(1);
        $this->actingAs($executive)->get(route('complaint-cases'))->assertOk()->assertSee('Unfair shift assignments');
        Livewire::actingAs($executive)->test(Complaints::class)
            ->call('open', $complaint->id)
            ->set('status', ComplaintStatus::Resolved->value)
            ->set('resolution', 'Weekend shifts now rotate across the whole HR team from November.')
            ->call('save')->assertHasNoErrors();

        Notification::assertSentTo($hrClerk, ComplaintNotice::class, fn (ComplaintNotice $notice) => ! $notice->forHr
            && $notice->toArray($hrClerk)['body'] === 'The President or Vice President wrote the outcome. Open My Complaints to read it.');
        Livewire::actingAs($hrClerk)->test(MyComplaints::class)->assertSee('Outcome from the President or Vice President');
    });
});

describe('company policy', function () {
    it('is written by Admin or HR and read by everyone', function () {
        Livewire::actingAs(staff(Role::Hr))->test(CompanyPolicies::class)
            ->call('create')
            ->set('title', 'Uniform and grooming')
            ->set('body', 'Wear the complete prescribed uniform on duty.')
            ->call('save')->assertHasNoErrors();

        expect(CompanyPolicy::query()->sole()->title)->toBe('Uniform and grooming');

        $this->actingAs(employee())->get(route('company-policy'))->assertOk()->assertSee('Uniform and grooming')->assertDontSee('Add section');

        Livewire::actingAs(employee())->test(CompanyPolicies::class)->call('create')->assertForbidden();
    });
});
