<?php

use App\Actions\Payroll\AdvancePayrollRun;
use App\Actions\Payroll\GenerateRunPayslips;
use App\Actions\Payroll\StartPayslipGeneration;
use App\Enums\Role;
use App\Jobs\GenerateRunPayslipsJob;
use App\Livewire\Admin\Payroll;
use App\Models\PayslipRecord;
use App\Models\TimeLog;
use App\Notifications\UpdateNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// "Generate all" runs in the background (thousands of employees shouldn't hang one request):
// progress saved on the run, a bar on the page, one generation per run, resumable, and a bell
// to whoever started it.

beforeEach(function () {
    $this->accounting = staff(Role::Accounting);
    $this->run = payrollRun('2026-09-01', '2026-09-15', 'processing');
    $this->guards = collect(range(1, 3))->map(function () {
        $guard = employee();
        TimeLog::factory()->on(CarbonImmutable::parse('2026-09-02'))->create(['user_id' => $guard->id]);

        return $guard;
    });
});

it('queues the job, counts who is due, and shows a progress bar meanwhile', function () {
    Queue::fake();

    Livewire::actingAs($this->accounting)->test(Payroll::class)
        ->call('viewRun', $this->run->id)->call('generateAll')
        ->assertDispatched('notify', message: 'Generating payslips in the background. You can leave this page; you\'ll get a bell when it\'s done.')
        ->assertSee('Waiting for the background worker')->assertSee('0 of 3')->assertSee('Generating…');

    Queue::assertPushed(GenerateRunPayslipsJob::class, fn (GenerateRunPayslipsJob $job) => $job->runId === $this->run->id && $job->actorId === $this->accounting->id);
    expect($this->run->fresh()?->generation_status)->toBe('queued')
        ->and($this->run->fresh()?->generation_total)->toBe(3)
        ->and(PayslipRecord::query()->count())->toBe(0); // nothing built in the click itself

    // One at a time, and no approving while it runs.
    expect(fn () => app(StartPayslipGeneration::class)->handle($this->run->fresh(), $this->accounting))->toThrow(ValidationException::class, 'already being generated')
        ->and(app(AdvancePayrollRun::class)->approvalBlockedReason($this->run->fresh()))->toBe('Payslips are still being generated. Approve once that finishes.')
        ->and(fn () => app(StartPayslipGeneration::class)->handle($this->run->fresh(), staff(Role::Hr)))->toThrow(AuthorizationException::class);
});

it('builds every payslip in the job, saves progress, and rings whoever started it', function () {
    Notification::fake();
    Queue::fake();
    app(StartPayslipGeneration::class)->handle($this->run, $this->accounting);

    (new GenerateRunPayslipsJob($this->run->id, $this->accounting->id))->handle(app(GenerateRunPayslips::class));

    $run = $this->run->fresh();
    expect($run?->generation_status)->toBe('done')
        ->and($run?->generation_done)->toBe(3)->and($run?->generation_generated)->toBe(3)->and($run?->generationPercent())->toBe(100)
        ->and($run?->generation_finished_at)->not->toBeNull()
        ->and(PayslipRecord::query()->where('payroll_run_id', $this->run->id)->count())->toBe(3);
    Notification::assertSentTo($this->accounting, UpdateNotice::class, fn (UpdateNotice $n) => $n->title === 'Payslips generated: '.$this->run->periodLabel() && $n->body === '3 generated.');
});

it('reports after every batch, and carries on where an interrupted run stopped', function () {
    $batches = [];
    app(GenerateRunPayslips::class)->handle($this->run, $this->accounting, $noRate, function (int $processed, int $generated) use (&$batches) {
        $batches[] = [$processed, $generated];
    });
    expect($batches)->toBe([[3, 3]]); // fewer than 100: one batch

    // A new guard after the first pass: running again makes only the missing one.
    $late = employee();
    TimeLog::factory()->on(CarbonImmutable::parse('2026-09-03'))->create(['user_id' => $late->id]);
    expect(app(GenerateRunPayslips::class)->countDue($this->run))->toBe(1)
        ->and(app(GenerateRunPayslips::class)->handle($this->run, $this->accounting))->toBe(1);
});

it('marks a failed run and tells the starter how to carry on', function () {
    Notification::fake();
    $this->run->forceFill(['generation_status' => 'running', 'generation_total' => 3, 'generation_done' => 1])->save();

    (new GenerateRunPayslipsJob($this->run->id, $this->accounting->id))->failed(new RuntimeException('worker died'));

    expect($this->run->fresh()?->generation_status)->toBe('failed');
    Notification::assertSentTo($this->accounting, UpdateNotice::class, fn (UpdateNotice $n) => str_starts_with($n->title, 'Payslip generation stopped') && str_contains($n->body, 'Generate all again'));

    Livewire::actingAs($this->accounting)->test(Payroll::class)->call('viewRun', $this->run->id)
        ->assertSee('Generating stopped partway (1 of 3)')->assertSee('Generate all');
});
