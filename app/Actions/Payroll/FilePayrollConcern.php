<?php

namespace App\Actions\Payroll;

use App\Enums\Ability;
use App\Enums\AuditArea;
use App\Enums\PayrollConcernCategory;
use App\Models\PayrollConcern;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayrollConcernNotice;
use App\Services\AuditLogger;
use App\Services\Files\PrivateUploadStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee reports a problem on one of their payslips (roadmap item 14):
 * what's wrong, in their words, and optionally a photo or PDF (a DTR, a
 * supervisor's note). Accounting is told at once, by bell and email.
 *
 * One open report per kind of problem per payslip, so a double tap doesn't
 * file it twice.
 */
final class FilePayrollConcern
{
    public function __construct(
        private readonly PrivateUploadStore $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $employee, PayrollRun $run, PayrollConcernCategory $category, string $details, ?UploadedFile $attachment = null): PayrollConcern
    {
        $employee->loadMissing('employeeProfile');

        if ($employee->employeeProfile === null || $employee->isPaidOutsidePayroll() || ! PayrollRun::query()->visibleToEmployees()->whereKey($run->id)->exists()) {
            throw ValidationException::withMessages(['details' => 'Problems can be reported on your own payslips only.']);
        }

        if (mb_strlen(trim($details)) < 10) {
            throw ValidationException::withMessages(['details' => 'Say what\'s wrong, with the dates (a sentence or two).']);
        }

        if (PayrollConcern::query()->open()->where('user_id', $employee->id)->where('payroll_run_id', $run->id)->where('category', $category)->exists()) {
            throw ValidationException::withMessages(['details' => 'You already reported this on this payslip. Accounting will answer it.']);
        }

        $disk = (string) config('payroll.concerns.disk');
        $stored = $attachment !== null ? $this->uploads->store($attachment, $disk, 'payroll-concerns/'.$employee->id, 'concern', ['png', 'jpg', 'pdf'], 'attachment') : null;

        try {
            return DB::transaction(function () use ($employee, $run, $category, $details, $stored) {
                $concern = new PayrollConcern;
                $concern->forceFill([
                    'user_id' => $employee->id,
                    'payroll_run_id' => $run->id,
                    'category' => $category,
                    'details' => trim($details),
                    'attachment_path' => $stored['path'] ?? null,
                    'attachment_name' => $stored['name'] ?? null,
                    'attachment_mime' => $stored['mime'] ?? null,
                ])->save();

                $this->audit->record(AuditArea::Payroll, 'concern.filed', "{$employee->name} reported a problem on the {$run->periodLabel()} payslip: {$category->label()}", $concern, causer: $employee);

                Notification::send(User::query()->notifiable(Ability::ManagePayroll->roles())->whereKeyNot($employee->id)->get(), new PayrollConcernNotice($concern, PayrollConcernNotice::FILED));

                return $concern;
            });
        } catch (\Throwable $e) {
            // Nothing saved: don't leave the file behind.
            $this->uploads->delete($disk, $stored['path'] ?? null);

            throw $e;
        }
    }
}
