<?php

use App\Actions\People\SubmitRequirementDocument;
use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use App\Enums\Role;
use App\Livewire\Admin\Employees;
use App\Livewire\People\RequirementsChecklist;
use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequirement;
use App\Models\User;
use App\Notifications\RequirementNotice;
use App\Notifications\RequirementSubmitted;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function pdfUpload(string $name = 'nbi.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/**
 * @param  array<string, mixed>  $attributes
 */
function requirementRecord(EmployeeProfile $profile, Requirement $requirement, array $attributes = []): EmployeeRequirement
{
    $record = new EmployeeRequirement;
    $record->forceFill(['employee_profile_id' => $profile->id, 'requirement' => $requirement, 'status' => RequirementStatus::OnFile, ...$attributes])->save();

    return $record;
}

function checklistFor(User $actor, EmployeeProfile $profile): Testable
{
    return Livewire::actingAs($actor)->test(RequirementsChecklist::class, ['profileId' => $profile->id]);
}

beforeEach(function () {
    Storage::fake((string) config('hr.documents.disk'));
    Notification::fake();

    $this->hr = staff(Role::Hr);
    $this->guard = employee();
    $this->profile = $this->guard->employeeProfile;
});

it('labels the old GKE report as RAE and skips guard-only documents for office staff', function () {
    expect(Requirement::RaeReport->label())->toBe('RAE Opening and Closing Report')
        ->and(Requirement::for(isOfficeStaff: false))->toHaveCount(21)
        ->and(Requirement::for(isOfficeStaff: true))->not->toContain(Requirement::SecurityLicense)
        ->and(Requirement::for(isOfficeStaff: true))->toContain(Requirement::NbiClearance);
});

it('lets an employee upload a document, which waits for HR and notifies HR', function () {
    checklistFor($this->guard, $this->profile)
        ->call('startUpload', Requirement::NbiClearance->value)
        ->set('document', pdfUpload())
        ->set('expiresOn', '2027-03-01')
        ->call('submitDocument')
        ->assertHasNoErrors()
        ->assertSee('Awaiting HR check');

    $record = EmployeeRequirement::query()->sole();
    expect($record->status)->toBe(RequirementStatus::Submitted)
        ->and($record->verified_by)->toBeNull()
        ->and($record->expires_on?->toDateString())->toBe('2027-03-01')
        ->and($record->file_name)->toBe('nbi.pdf');

    Storage::disk((string) config('hr.documents.disk'))->assertExists((string) $record->file_path);
    Notification::assertSentTo($this->hr, RequirementSubmitted::class);
    expect(ActivityLog::query()->where('event', 'requirement.submitted')->count())->toBe(1);
});

it('never lets the employee verify their own document', function () {
    checklistFor($this->guard, $this->profile)
        ->call('startReview', Requirement::NbiClearance->value)
        ->assertForbidden();
});

it('lets HR verify a submission with its expiry date', function () {
    checklistFor($this->guard, $this->profile)
        ->call('startUpload', Requirement::NbiClearance->value)->set('document', pdfUpload())->call('submitDocument');

    checklistFor($this->hr, $this->profile)
        ->call('startReview', Requirement::NbiClearance->value)
        ->set('decision', 'file')
        ->set('expiresOn', '2027-06-30')
        ->call('review')
        ->assertHasNoErrors();

    expect(EmployeeRequirement::query()->sole())
        ->status->toBe(RequirementStatus::OnFile)
        ->verified_by->toBe($this->hr->id)
        ->expires_on->toDateString()->toBe('2027-06-30');
    expect(ActivityLog::query()->where('event', 'requirement.verified')->count())->toBe(1);
});

it('returns a document with a reason, deletes the upload and tells the employee', function () {
    checklistFor($this->guard, $this->profile)
        ->call('startUpload', Requirement::BirthCertificate->value)->set('document', pdfUpload('birth.pdf'))->call('submitDocument');
    $path = (string) EmployeeRequirement::query()->sole()->file_path;

    checklistFor($this->hr, $this->profile)
        ->call('startReview', Requirement::BirthCertificate->value)
        ->set('decision', 'return')
        ->call('review')
        ->assertHasErrors(['note' => 'required'])
        ->set('note', 'Blurry scan, please rescan')
        ->call('review')
        ->assertHasNoErrors();

    expect(EmployeeRequirement::query()->sole())
        ->status->toBe(RequirementStatus::Missing)
        ->file_path->toBeNull()
        ->note->toBe('Blurry scan, please rescan');
    Storage::disk((string) config('hr.documents.disk'))->assertMissing($path);
    Notification::assertSentTo($this->guard, RequirementNotice::class, fn (RequirementNotice $notice) => $notice->reason === RequirementNotice::RETURNED && $notice->email);
});

it('files HR uploads at once and records paper copies without a file', function () {
    checklistFor($this->hr, $this->profile)
        ->call('startUpload', Requirement::SssE1->value)->set('document', UploadedFile::fake()->image('sss.jpg'))->call('submitDocument')
        ->call('startReview', Requirement::TinId->value)->set('decision', 'file')->call('review');

    $records = EmployeeRequirement::query()->get()->keyBy(fn (EmployeeRequirement $record) => $record->requirement->value);

    expect($records['sss_e1']->status)->toBe(RequirementStatus::OnFile)
        ->and($records['sss_e1']->file_mime)->toBe('image/jpeg')
        ->and($records['tin_id']->status)->toBe(RequirementStatus::OnFile)
        ->and($records['tin_id']->hasFile())->toBeFalse();
    Notification::assertNotSentTo($this->hr, RequirementSubmitted::class);
});

it('deletes the replaced file when a document is uploaded again', function () {
    $component = checklistFor($this->guard, $this->profile);
    $component->call('startUpload', Requirement::NbiClearance->value)->set('document', pdfUpload('old.pdf'))->call('submitDocument');
    $oldPath = (string) EmployeeRequirement::query()->sole()->file_path;

    $component->call('startUpload', Requirement::NbiClearance->value)->set('document', pdfUpload('new.pdf'))->call('submitDocument');

    Storage::disk((string) config('hr.documents.disk'))->assertMissing($oldPath);
    expect(EmployeeRequirement::query()->sole()->file_name)->toBe('new.pdf');
});

it('refuses files that only pretend to be PDFs', function () {
    checklistFor($this->guard, $this->profile)
        ->call('startUpload', Requirement::NbiClearance->value)
        ->set('document', UploadedFile::fake()->createWithContent('nbi.pdf', '<?php echo "hi";'))
        ->call('submitDocument')
        ->assertHasErrors('document');

    // The Action checks the bytes itself too, for callers that skip validation.
    expect(fn () => app(SubmitRequirementDocument::class)->handle($this->profile, Requirement::NbiClearance, UploadedFile::fake()->createWithContent('x.pdf', 'plain text'), null, $this->guard))
        ->toThrow(ValidationException::class);
    expect(EmployeeRequirement::query()->count())->toBe(0);
});

it('does not ask office staff for guard-only documents', function () {
    $office = staff(Role::Accounting);

    expect(fn () => app(SubmitRequirementDocument::class)->handle($office->employeeProfile, Requirement::SecurityLicense, pdfUpload(), null, $office))
        ->toThrow(ValidationException::class);
});

it('keeps each 201 file private to its owner and HR, and audits every view', function () {
    $record = requirementRecord($this->profile, Requirement::NbiClearance, ['file_path' => '201/x/nbi.pdf', 'file_name' => 'nbi.pdf', 'file_mime' => 'application/pdf']);
    Storage::disk((string) config('hr.documents.disk'))->put('201/x/nbi.pdf', '%PDF-1.4');

    $this->actingAs($this->guard)->get(route('requirements.document', $record))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->hr)->get(route('requirements.document', $record))->assertOk();
    $this->actingAs(employee())->get(route('requirements.document', $record))->assertForbidden();
    $this->actingAs(staff(Role::Accounting))->get(route('requirements.document', $record))->assertForbidden();

    expect(ActivityLog::query()->where('event', 'requirement.document_viewed')->count())->toBe(2);
    checklistFor(employee(), $this->profile)->assertForbidden();
});

it('records a promised date and reminds everyone once it passes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00'));

    checklistFor($this->guard, $this->profile)
        ->call('startPromise', Requirement::DrugTest->value)
        ->set('promisedOn', '2026-10-01')->call('promise')->assertHasErrors(['promisedOn' => 'after_or_equal'])
        ->set('promisedOn', '2026-10-12')->call('promise')->assertHasNoErrors()
        ->assertSee('Will submit by Oct 12, 2026');

    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 0 201 document alerts.');

    $this->travelTo(CarbonImmutable::parse('2026-10-13 07:05'));
    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 1 201 document alert.');
    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 0 201 document alerts.');

    Notification::assertSentToTimes($this->guard, RequirementNotice::class, 1);
    Notification::assertSentTo($this->hr, RequirementNotice::class, fn (RequirementNotice $notice) => $notice->reason === RequirementNotice::PROMISE_OVERDUE);
});

it('warns before a document expires at the nearest threshold only, and again after renewal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:05'));
    $admin = staff(Role::Admin);
    $accountant = staff(Role::Accounting);
    $record = requirementRecord($this->profile, Requirement::SecurityLicense, ['expires_on' => '2026-11-02']); // 25 days left

    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 1 201 document alert.');
    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 0 201 document alerts.');

    foreach ([$this->guard, $this->hr, $admin] as $recipient) {
        Notification::assertSentTo($recipient, RequirementNotice::class, fn (RequirementNotice $notice) => $notice->reason === RequirementNotice::EXPIRING && $notice->email);
    }
    Notification::assertNotSentTo($accountant, RequirementNotice::class);

    // A week before: the 7-day alert. After expiry: the expired alert.
    $this->travelTo(CarbonImmutable::parse('2026-10-27 07:05'));
    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 1 201 document alert.');
    $this->travelTo(CarbonImmutable::parse('2026-11-03 07:05'));
    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 1 201 document alert.');
    Notification::assertSentTo($this->guard, RequirementNotice::class, fn (RequirementNotice $notice) => $notice->reason === RequirementNotice::EXPIRED);

    // Renewed: a new expiry date gets its own alerts.
    $record->forceFill(['expires_on' => '2026-12-01'])->save();
    $this->artisan('app:send-requirement-alerts')->expectsOutput('Sent 1 201 document alert.');
});

it('shows completion on the checklist and in the employee list', function () {
    requirementRecord($this->profile, Requirement::NbiClearance, ['expires_on' => now()->addYear()->toDateString()]);
    requirementRecord($this->profile, Requirement::PoliceClearance, ['expires_on' => now()->subDay()->toDateString()]);

    checklistFor($this->hr, $this->profile)
        ->assertSee('1 of 21 documents on file')
        ->assertSee('1 expired')
        ->assertSee('Only what needs action (20 of 21)')
        ->set('onlyOpen', true)
        ->assertDontSee('NBI Clearance');

    Livewire::actingAs($this->hr)->test(Employees::class)->assertSee('1/21');
});

it('offers "Only what needs action" only when it would change the list', function () {
    // Nothing on file yet: every document needs action, so the filter would hide nothing.
    checklistFor($this->hr, $this->profile)->assertSee('201 file')->assertDontSee('Only what needs action');

    requirementRecord($this->profile, Requirement::NbiClearance, ['expires_on' => now()->addYear()->toDateString()]);
    checklistFor($this->hr, $this->profile)->assertSee('Only what needs action (20 of 21)');
});

it('renders the checklist on My Profile and on the employee record', function () {
    $this->actingAs($this->guard)->get(route('profile'))->assertOk()->assertSee('201 file')->assertSee('RAE Opening and Closing Report');
    $this->actingAs($this->hr)->get(route('employees.show', $this->profile))->assertOk()->assertSee('201 file');
});
