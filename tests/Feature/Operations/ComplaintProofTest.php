<?php

use App\Actions\Complaints\AttachComplaintFile;
use App\Actions\Complaints\FileComplaint;
use App\Actions\Complaints\RequestComplaintProof;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Livewire\Admin\Complaints;
use App\Livewire\Complaints\MyComplaints;
use App\Models\ActivityLog;
use App\Models\Complaint;
use App\Notifications\ComplaintNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Proof on a complaint is optional (RA 11313 / RA 7877: a complaint is investigated
// with or without it). The employee can attach it when filing or later, say who saw
// it, and HR can ask for it. Only the complainant and HR open the files.

beforeEach(function () {
    Storage::fake((string) config('complaints.files_disk'));
    $this->guard = employee();
    $this->hr = staff(Role::Hr);
});

/** A complaint filed through the page, with whatever extra fields are given. */
function fileComplaintAs($guard, array $fields = []): Complaint
{
    $page = Livewire::actingAs($guard)->test(MyComplaints::class)
        ->set('category', ComplaintCategory::Conduct->value)->set('subjectId', 'other')->set('subject', 'Shouted at during the night shift')
        ->set('details', 'The shift supervisor shouted at me in front of the client for twenty minutes.')
        ->set('acknowledges', true);

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('submit')->assertHasNoErrors();

    return Complaint::query()->latest('id')->firstOrFail();
}

it('files a complaint without any proof', function () {
    $complaint = fileComplaintAs($this->guard);

    expect($complaint->files()->count())->toBe(0)->and($complaint->witnesses)->toBeNull();
});

it('takes proof and who saw it when filing, and keeps the files private', function () {
    $complaint = fileComplaintAs($this->guard, [
        'witnesses' => 'Jun Reyes, the day guard at Gate 2',
        'proof' => [UploadedFile::fake()->image('screenshot.png'), UploadedFile::fake()->image('photo.jpg')],
    ]);

    expect($complaint->witnesses)->toBe('Jun Reyes, the day guard at Gate 2')
        ->and($complaint->files)->toHaveCount(2);

    $file = $complaint->files->first();
    Storage::disk((string) config('complaints.files_disk'))->assertExists($file->path);

    // The complainant and HR open it; HR's view is audited, anyone else gets 403.
    $this->actingAs($this->guard)->get(route('complaint.file', $file))->assertOk();
    $this->actingAs($this->hr)->get(route('complaint.file', $file))->assertOk();
    expect(ActivityLog::query()->where('event', 'complaint.proof_viewed')->count())->toBe(1);

    $this->actingAs(employee())->get(route('complaint.file', $file))->assertForbidden();
    $this->actingAs(staff(Role::Admin))->get(route('complaint.file', $file))->assertForbidden();
});

it('refuses a file that is not a picture or PDF, and too many files', function () {
    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->set('subjectId', 'other')->set('subject', 'Late relief')->set('details', 'The relief guard came two hours late again this week.')->set('acknowledges', true)
        ->set('proof', [UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
        ->call('submit')->assertHasErrors(['proof.0']);

    $complaint = fileComplaintAs($this->guard);
    foreach (range(1, (int) config('complaints.max_files')) as $i) {
        app(AttachComplaintFile::class)->handle($this->guard, $complaint, UploadedFile::fake()->image("p{$i}.jpg"));
    }

    expect(fn () => app(AttachComplaintFile::class)->handle($this->guard, $complaint, UploadedFile::fake()->image('one-more.jpg')))
        ->toThrow(ValidationException::class, 'Up to '.config('complaints.max_files').' files');
});

it('lets HR ask for proof, and the employee add it later', function () {
    Notification::fake();
    $complaint = fileComplaintAs($this->guard);

    Livewire::actingAs($this->hr)->test(Complaints::class)
        ->call('open', $complaint->id)
        ->assertSee('None attached.')
        ->set('askingProof', true)
        ->call('askForProof')->assertHasErrors(['proofNote'])
        ->set('proofNote', 'A screenshot of the messages, if you kept them.')
        ->call('askForProof')->assertHasNoErrors()
        ->assertSee('They haven\'t added any since', escape: false);

    expect($complaint->fresh()?->awaitsProof())->toBeTrue();
    Notification::assertSentTo($this->guard, ComplaintNotice::class, fn (ComplaintNotice $notice) => $notice->event === ComplaintNotice::PROOF_REQUESTED
        && ! str_contains($notice->toArray($this->guard)['body'], 'screenshot')); // the bell never carries the contents

    Livewire::actingAs($this->guard)->test(MyComplaints::class)
        ->assertSee('HR asks for proof, if you have any')->assertSee('A screenshot of the messages, if you kept them.')
        ->call('startAddingProof', $complaint->id)
        ->set('extraProof', UploadedFile::fake()->image('messages.png'))
        ->call('addProof')->assertHasNoErrors()
        ->assertDontSee('HR asks for proof, if you have any');

    expect($complaint->fresh()?->files)->toHaveCount(1)
        ->and($complaint->fresh()?->awaitsProof())->toBeFalse();
    Notification::assertSentTo($this->hr, ComplaintNotice::class, fn (ComplaintNotice $notice) => $notice->event === ComplaintNotice::PROOF_ADDED);
    expect(ActivityLog::query()->where('event', 'complaint.proof_added')->value('description'))->toBe('Proof added to complaint '.$complaint->reference());
});

it('takes no proof on a closed case, nor on someone else\'s', function () {
    $complaint = fileComplaintAs($this->guard);

    $this->actingAs(employee());
    expect(fn () => app(AttachComplaintFile::class)->handle(employee(), $complaint, UploadedFile::fake()->image('x.jpg')))
        ->toThrow(AuthorizationException::class);
    Livewire::actingAs(employee())->test(MyComplaints::class)->call('startAddingProof', $complaint->id)->assertStatus(404);

    $complaint->forceFill(['status' => ComplaintStatus::Resolved, 'resolution' => 'Talked to the supervisor.'])->save();
    expect(fn () => app(AttachComplaintFile::class)->handle($this->guard, $complaint, UploadedFile::fake()->image('late.jpg')))
        ->toThrow(ValidationException::class, 'This complaint is closed.')
        ->and(fn () => app(RequestComplaintProof::class)->handle($this->hr, $complaint, 'Anything else?'))
        ->toThrow(ValidationException::class, 'This complaint is closed.');
});

it('keeps resignation-opened complaints working without proof', function () {
    $complaint = app(FileComplaint::class)->handle($this->guard, ComplaintCategory::Conduct, 'Just cause given for resigning: inhuman treatment', 'My letter.', null, null);

    expect($complaint->files()->count())->toBe(0)->and($complaint->witnesses)->toBeNull();
});
