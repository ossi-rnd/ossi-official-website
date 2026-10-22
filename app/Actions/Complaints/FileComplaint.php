<?php

namespace App\Actions\Complaints;

use App\Enums\AuditArea;
use App\Enums\ComplaintCategory;
use App\Models\Complaint;
use App\Models\ComplaintSubject;
use App\Models\User;
use App\Notifications\ComplaintNotice;
use App\Services\AuditLogger;
use App\Support\ComplaintRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Any employee files a complaint; its handler is told it exists (not what it
 * says, outside the app): HR, or the President or Vice President when the complainant is in HR.
 * The audit log records the filing, never the contents.
 */
final class FileComplaint
{
    public function __construct(private readonly AuditLogger $audit, private readonly AttachComplaintFile $attach) {}

    /**
     * $acknowledged: the employee ticked "I've read these" on the form; the lines they read
     * (Complaint::acknowledgementLines) are saved word for word, with the time.
     * $witnesses and $proof are optional: no complaint needs proof to be filed.
     * $asksForHelp: "I need help now": the handler is alerted at once (bell and email).
     * $exemptFromLimit: opened by something else (a resignation for a just cause).
     * $picked: a common subject from HR's list; its words become the subject (else $subject, typed).
     *
     * @param  list<UploadedFile>  $proof
     */
    public function handle(User $employee, ComplaintCategory $category, string $subject, string $details, ?string $against, ?CarbonImmutable $incidentOn, bool $acknowledged = false, string $locale = 'en', ?string $witnesses = null, array $proof = [], bool $asksForHelp = false, bool $exemptFromLimit = false, ?ComplaintSubject $picked = null): Complaint
    {
        if ($picked !== null && ($picked->category !== $category || ! $picked->is_active)) {
            throw ValidationException::withMessages(['subjectId' => 'Choose a subject from the list for this category, or "Other".']);
        }

        // The subject's words are copied: renaming it later doesn't rewrite this complaint.
        $subject = $picked->label ?? $subject;

        // The weekly limit (HR's, ComplaintRules) never stands between someone and help, or a harassment complaint.
        if (! $asksForHelp && ! $exemptFromLimit && $category !== ComplaintCategory::Harassment) {
            $this->checkWeeklyLimit($employee);
        }

        if ($incidentOn !== null && $incidentOn->isFuture()) {
            throw ValidationException::withMessages(['incidentOn' => 'The date it happened can\'t be in the future.']);
        }

        if (count($proof) > (int) config('complaints.max_files')) {
            throw ValidationException::withMessages(['proof' => 'Up to '.config('complaints.max_files').' files. Put several pages or screenshots in one PDF.']);
        }

        return DB::transaction(function () use ($employee, $category, $subject, $details, $against, $incidentOn, $acknowledged, $locale, $witnesses, $proof, $asksForHelp, $picked) {
            $forExecutive = Complaint::goesToExecutive($employee);
            $complaint = new Complaint;
            $complaint->forceFill([
                'user_id' => $employee->id,
                'for_executive' => $forExecutive,
                'asks_for_help' => $asksForHelp,
                'acknowledgement' => $acknowledged ? Complaint::acknowledgementLines($forExecutive ? 'the President or Vice President' : 'HR', $locale) : null,
                'acknowledged_at' => $acknowledged ? now() : null,
                'category' => $category,
                'complaint_subject_id' => $picked?->id,
                'subject' => trim($subject),
                'details' => trim($details),
                'against' => filled($against) ? trim($against) : null,
                'witnesses' => filled($witnesses) ? trim($witnesses) : null,
                'incident_on' => $incidentOn?->toDateString(),
            ])->save();

            // The handler is told once, below, with the complaint.
            foreach ($proof as $file) {
                $this->attach->handle($employee, $complaint, $file, notify: false);
            }

            $this->audit->record(AuditArea::Complaints, 'complaint.filed', "Complaint {$complaint->reference()} filed", $complaint, causer: $employee);

            $handlers = User::query()->notifiable($complaint->handlerAbility()->roles())->whereKeyNot($employee->id)->get();
            Notification::send($handlers, new ComplaintNotice($complaint->setRelation('user', $employee), forHr: true));

            return $complaint;
        });
    }

    /**
     * HR's limit on complaints in 7 days (ComplaintRules::allowanceFor, the same
     * count the form's counter shows; 0 = none).
     */
    private function checkWeeklyLimit(User $employee): void
    {
        ['limit' => $limit, 'used' => $used, 'left' => $left, 'nextAt' => $nextAt] = ComplaintRules::allowanceFor($employee);
        $first = $used->first();

        if ($left !== 0 || $first === null || $nextAt === null) {
            return;
        }

        $which = $limit === 1 ? "You filed {$first->reference()} on {$first->created_at->format('M j')}" : "You filed {$limit} complaints in the last 7 days";

        throw ValidationException::withMessages(['limit' => "{$which}. You can file another from {$nextAt->format('M j, g:i A')}, or add to an open one on this page. Harassment, and asking for help, can be sent any time."]);
    }
}
