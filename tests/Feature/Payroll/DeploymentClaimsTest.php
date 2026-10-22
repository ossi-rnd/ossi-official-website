<?php

use App\Actions\Payroll\CancelReimbursement;
use App\Actions\Payroll\DecideReimbursement;
use App\Actions\Payroll\FileReimbursement;
use App\Actions\Payroll\GeneratePayslip;
use App\Enums\AbsenceNoticeStatus;
use App\Enums\Region;
use App\Enums\ReimbursementCategory;
use App\Enums\ReimbursementStatus;
use App\Enums\Role;
use App\Livewire\Admin\DeploymentClaims;
use App\Livewire\Admin\Relievers;
use App\Livewire\Reimbursements\MyReimbursements;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\Reimbursement;
use App\Models\TimeLog;
use App\Notifications\ReimbursementNotice;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// Deployment costs (roadmap 5): Operations claims what a guard paid for a deployment;
// the Executive approves it like any reimbursement; the guard is paid back on their
// payslip. Operations staff's own deployment costs are a "Deployment" reimbursement.

beforeEach(function () {
    Notification::fake();
    Storage::fake((string) config('payroll.concerns.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));

    $cavite = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->guard = employee(['client_id' => $cavite->id, 'detachment' => $cavite->name, 'post' => 'Main Entrance']);
    $this->opsHq = staff(Role::OperationsHq);
    $this->executive = staff(Role::President);
    $this->accounting = staff(Role::Accounting);
});

function claimFor(object $test, $filer, $guard, float $amount = 350.0): Reimbursement
{
    return app(FileReimbursement::class)->handle($guard, ReimbursementCategory::Transportation, CarbonImmutable::parse('2026-10-15'), $amount,
        'Grab from Imus to Dasmariñas to relieve the night guard.', UploadedFile::fake()->image('grab.jpg'), acknowledged: true, filedBy: $filer);
}

it('lets Operations claim a guard\'s deployment cost, paid on the guard\'s next payslip', function () {
    $claim = claimFor($this, $this->opsHq, $this->guard);

    expect($claim->user_id)->toBe($this->guard->id)
        ->and($claim->filed_by)->toBe($this->opsHq->id)
        ->and($claim->category)->toBe(ReimbursementCategory::Deployment) // always a deployment cost
        ->and($claim->deployment_for)->toContain('Metrobank – Imus') // their post, by default
        ->and($claim->acknowledgement[0])->toStartWith('The receipt is genuine, and the guard spent this money on a deployment I ordered');
    Notification::assertSentTo($this->guard, ReimbursementNotice::class, fn (ReimbursementNotice $n) => $n->event === ReimbursementNotice::FILED_FOR_YOU
        && str_contains($n->toArray($this->guard)['body'], 'Filed by '.$this->opsHq->name));
    Notification::assertSentTo($this->executive, ReimbursementNotice::class, fn (ReimbursementNotice $n) => $n->event === ReimbursementNotice::FILED);

    app(DecideReimbursement::class)->handle($this->executive, $claim, true, null);
    Notification::assertSentTo($this->opsHq, ReimbursementNotice::class, fn (ReimbursementNotice $n) => $n->event === ReimbursementNotice::DECIDED
        && $n->toArray($this->opsHq)['title'] === "Deployment claim approved: {$this->guard->name}, ₱350.00");

    $run = payrollRun('2026-10-16', '2026-10-31');
    TimeLog::factory()->on(CarbonImmutable::parse('2026-10-16'))->create(['user_id' => $this->guard->id]);
    $payslip = app(GeneratePayslip::class)->handle($run, $this->guard, $this->accounting);
    expect(collect($payslip->adjustment_lines)->sole()['label'])->toStartWith('Reimbursement: Deployment, Oct 15');

    // The guard sees it (no "File" button), and who filed it.
    expect(collect(Navigation::for($this->guard))->pluck('items')->flatten(1)->pluck('route'))->toContain('reimbursements');
    Livewire::actingAs($this->guard)->test(MyReimbursements::class)
        ->assertSee('Filed for you by '.$this->opsHq->name)->assertDontSee('File a reimbursement');
});

it('keeps a Satellite to its region, guards only, and never lets the filer approve or the guard cancel', function () {
    $satellite = staff(Role::SatelliteOperations);
    $satellite->forceFill(['operations_region' => Region::Ncr->value])->save();
    expect(fn () => claimFor($this, $satellite, $this->guard))->toThrow(AuthorizationException::class);

    expect(fn () => claimFor($this, $this->opsHq, employee(['is_office_staff' => true])))->toThrow(AuthorizationException::class);
    expect(fn () => claimFor($this, staff(Role::Hr), $this->guard))->toThrow(AuthorizationException::class);

    $admin = staff(Role::SuperAdmin);
    $claim = claimFor($this, $admin, $this->guard);
    expect(fn () => app(DecideReimbursement::class)->handle($admin, $claim, true, null))->toThrow(AuthorizationException::class);

    expect(fn () => app(CancelReimbursement::class)->handle($this->guard, $claim))->toThrow(AuthorizationException::class);
    app(CancelReimbursement::class)->handle($admin, $claim);
    expect($claim->fresh()?->status)->toBe(ReimbursementStatus::Cancelled);
});

it('works from the Deployment Costs page, and asks office staff which deployment their own cost was for', function () {
    Livewire::actingAs($this->opsHq)->test(DeploymentClaims::class)
        ->call('start')
        ->call('choose', $this->guard->id)
        ->assertSet('deploymentFor', $this->guard->employeeProfile->assignmentLabel())
        ->set('amount', '280')->set('purpose', 'Jeep and tricycle to the Dasmariñas post, emergency relief.')
        ->set('receipt', UploadedFile::fake()->image('fare.jpg'))
        ->set('acknowledges', true)
        ->call('file')->assertHasNoErrors()
        ->assertSee($this->guard->name);
    expect(Reimbursement::query()->sole()->filed_by)->toBe($this->opsHq->id);

    $this->actingAs(staff(Role::Hr))->get(route('deployment-claims'))->assertForbidden();

    // Operations staff's own cost: a "Deployment" reimbursement, saying which deployment.
    $clerk = employee(['is_office_staff' => true]);
    expect(fn () => app(FileReimbursement::class)->handle($clerk, ReimbursementCategory::Deployment, CarbonImmutable::today(), 200, 'Fare to bring the reliever to the post.', UploadedFile::fake()->image('r.jpg')))
        ->toThrow(ValidationException::class, 'Say which deployment');
});

it('claims a reliever\'s fare from the relief: prefilled, linked, and once', function () {
    $absent = employee(['client_id' => $this->guard->employeeProfile->client_id, 'detachment' => 'Metrobank – Imus', 'post' => 'Main Entrance']);
    $relief = new AbsenceNotice;
    $relief->forceFill([
        'user_id' => $absent->id, 'duty_date' => '2026-10-15', 'reason' => 'Fever', 'source' => 'guard',
        'client_id' => $this->guard->employeeProfile->client_id, 'detachment' => 'Metrobank – Imus', 'post' => 'Main Entrance',
        'status' => AbsenceNoticeStatus::Covered, 'reliever_id' => $this->guard->id, 'covered_by' => $this->opsHq->id, 'covered_at' => now(),
    ])->save();

    // Suggested on Deployment Costs, and opened from Relievers (?relief=) already filled.
    Livewire::actingAs($this->opsHq)->test(DeploymentClaims::class)->assertSee('Reliefs to claim')->assertSee($absent->name);
    Livewire::withQueryParams(['relief' => $relief->id])->actingAs($this->opsHq)->test(DeploymentClaims::class)
        ->assertSet('filing', true)
        ->assertSet('guardId', $this->guard->id)
        ->assertSet('deploymentFor', "Relief at Metrobank – Imus · Main Entrance, Oct 15, for {$absent->name}")
        ->assertSet('spentOn', '2026-10-15')
        ->set('amount', '280')->set('purpose', 'Jeep and tricycle from home to the Imus post.')
        ->set('receipt', UploadedFile::fake()->image('fare.jpg'))->set('acknowledges', true)
        ->call('file')->assertHasNoErrors()
        ->assertDontSee('Reliefs to claim');

    expect(Reimbursement::query()->sole()->absence_notice_id)->toBe($relief->id);

    // Once: a second claim for the same relief is refused.
    expect(fn () => app(FileReimbursement::class)->handle($this->guard, ReimbursementCategory::Deployment, CarbonImmutable::parse('2026-10-15'), 100,
        'The same fare again, by mistake.', UploadedFile::fake()->image('again.jpg'), filedBy: staff(Role::OperationsHq), relief: $relief->fresh()))
        ->toThrow(ValidationException::class, 'already claimed');

    // Relievers shows the claim on the relief.
    Livewire::actingAs($this->opsHq)->test(Relievers::class)->set('filter', 'all')->assertSee('Fare claimed ₱280.00');

    // A relief not done yet can't have its fare claimed.
    $relief->forceFill(['duty_date' => '2026-10-20'])->save();
    expect($relief->fresh()?->fareClaimable())->toBeFalse();
});
