<?php

namespace Database\Seeders;

use App\Enums\AbsenceNoticeStatus;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\DocumentCategory;
use App\Enums\Role;
use App\Enums\TrainingAttendance;
use App\Models\AbsenceNotice;
use App\Models\Client;
use App\Models\CompanyDocument;
use App\Models\CompanyPolicy;
use App\Models\Complaint;
use App\Models\EmployeeProfile;
use App\Models\Training;
use App\Models\TrainingAttendee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Sample relievers, documents and licenses, trainings, complaints and
 * company policies. Written directly (not through the Actions) so seeding
 * sends no notifications.
 *
 * Safe on a database that already has data (`php artisan db:seed
 * --class=OperationsSampleSeeder`): each part is skipped when it has rows.
 */
class OperationsSampleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->whereHas('roles', fn ($query) => $query->whereIn('role', [Role::Admin->value, Role::SuperAdmin->value]))->orderBy('id')->first();
        $guards = User::query()->onPayroll()->whereHas('employeeProfile', fn ($query) => $query->where('is_office_staff', false))->with('employeeProfile')->orderBy('id')->get()->values();

        if ($admin === null || $guards->count() < 6) {
            $this->command->warn('Needs the demo accounts: run DemoSeeder first.');

            return;
        }

        $this->absences($guards);
        $this->documents($admin);
        $this->trainings($guards, $admin);
        $this->complaints($guards);
        $this->policies($admin);
    }

    /**
     * @param  Collection<int, User>  $guards
     */
    private function absences($guards): void
    {
        if (AbsenceNotice::query()->exists()) {
            return;
        }

        $opsHq = User::query()->whereHas('roles', fn ($query) => $query->where('role', Role::OperationsHq->value))->first();

        foreach ([
            [$guards->skip(2)->firstOrFail(), 2, 'Fever since last night, going to the clinic.', null],
            [$guards->skip(4)->firstOrFail(), 1, 'Family emergency in the province.', $guards->skip(5)->firstOrFail()],
        ] as [$guard, $daysAhead, $reason, $reliever]) {
            $profile = $guard->employeeProfile;
            $notice = new AbsenceNotice;
            $notice->forceFill([
                'user_id' => $guard->id,
                'duty_date' => today()->addDays($daysAhead)->toDateString(),
                'reason' => $reason,
                'client_id' => $profile?->client_id,
                'detachment' => $profile?->detachment,
                'post' => $profile?->post,
                'status' => $reliever !== null ? AbsenceNoticeStatus::Covered : AbsenceNoticeStatus::Open,
                'reliever_id' => $reliever?->id,
                'covered_by' => $reliever !== null ? $opsHq?->id : null,
                'covered_at' => $reliever !== null ? now()->subHour() : null,
            ])->save();
        }
    }

    private function documents(User $admin): void
    {
        if (CompanyDocument::query()->exists()) {
            return;
        }

        $disk = Storage::disk((string) config('documents.disk'));
        $today = CarbonImmutable::today();
        $metrobank = Client::query()->where('name', 'like', 'Metrobank%')->orderBy('name')->first();

        foreach ([
            [DocumentCategory::License, 'PNP-SOSIA License to Operate', 'LTO-2024-0815', $today->subYears(2)->addDays(20), $today->addDays(20)],
            [DocumentCategory::License, 'Mayor\'s Business Permit', 'BP-2026-11234', $today->subMonths(9), $today->addDays(75)],
            [DocumentCategory::License, 'Firearms registration (agency)', 'FA-77-2023', $today->subYears(3), $today->subDays(5)],
            [DocumentCategory::Company, 'SEC Certificate of Incorporation', 'CS2009-12345', $today->subYears(15), null],
            [DocumentCategory::Company, 'BIR Certificate of Registration (Form 2303)', '123-456-789-000', $today->subYears(15), null],
            [DocumentCategory::ClientLegal, 'Security service agreement', 'SSA-MBTC-2026', $today->subMonths(4), $today->addMonths(8)],
        ] as [$category, $title, $reference, $issued, $expires]) {
            $path = 'company-documents/'.$category->value.'-demo-'.str($title)->slug().'.pdf';
            $disk->put($path, "%PDF-1.4\n% Demo document: {$title}\n%%EOF\n");

            $document = new CompanyDocument;
            $document->forceFill([
                'category' => $category,
                'title' => $title,
                'client_id' => $category === DocumentCategory::ClientLegal ? $metrobank?->id : null,
                'reference_number' => $reference,
                'issued_on' => $issued->toDateString(),
                'expires_on' => $expires?->toDateString(),
                'file_path' => $path,
                'file_name' => str($title)->slug().'.pdf',
                'file_mime' => 'application/pdf',
                'file_size' => 64,
                'uploaded_by' => $admin->id,
            ])->save();
        }
    }

    /**
     * @param  Collection<int, User>  $guards
     */
    private function trainings($guards, User $admin): void
    {
        if (Training::query()->exists()) {
            return;
        }

        $today = CarbonImmutable::today();

        foreach ([
            ['Annual firearms refresher', 'PNP-SOSIA accredited range, Camp Crame', $today->subDays(20), $today->subDays(19), $today->addYear()->subDays(20), [6, 7, 8, 9, 10], 9],
            ['Basic first aid and CPR', 'Philippine Red Cross', $today->addDays(12), null, $today->addYears(2)->addDays(12), [2, 3, 11], null],
        ] as [$title, $provider, $starts, $ends, $validUntil, $indexes, $didNotComplete]) {
            $training = new Training(['title' => $title, 'provider' => $provider, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends?->toDateString(), 'venue' => $provider, 'valid_until' => $validUntil->toDateString()]);
            $training->created_by = $admin->id;
            $training->save();

            foreach ($indexes as $index) {
                $profile = $guards[$index]->employeeProfile ?? null;

                if (! $profile instanceof EmployeeProfile) {
                    continue;
                }

                $attendee = new TrainingAttendee;
                $attendee->forceFill([
                    'training_id' => $training->id,
                    'employee_profile_id' => $profile->id,
                    'status' => $starts->isFuture() ? TrainingAttendance::Enrolled : ($index === $didNotComplete ? TrainingAttendance::DidNotComplete : TrainingAttendance::Completed),
                ])->save();
            }
        }
    }

    /**
     * @param  Collection<int, User>  $guards
     */
    private function complaints($guards): void
    {
        if (Complaint::query()->exists()) {
            return;
        }

        foreach ([
            [$guards->skip(1)->firstOrFail(), ComplaintCategory::Assignment, 'Relief guard keeps arriving two hours late', 'For the past two weeks the night relief arrives around 8 PM instead of 6 PM, so I end up doing 14-hour shifts without overtime approval.', 'Night relief, BGC Tower One', ComplaintStatus::Open],
            [$guards->skip(3)->firstOrFail(), ComplaintCategory::Safety, 'Broken lock on the guardhouse door', 'The guardhouse door at Gate 1 does not lock since last month. Equipment and the logbook are left unsecured during rounds.', null, ComplaintStatus::Investigating],
        ] as [$guard, $category, $subject, $details, $against, $status]) {
            $complaint = new Complaint;
            $complaint->forceFill([
                'user_id' => $guard->id,
                'category' => $category,
                'subject' => $subject,
                'details' => $details,
                'against' => $against,
                'incident_on' => today()->subDays(5)->toDateString(),
                'status' => $status,
            ])->save();
        }
    }

    private function policies(User $admin): void
    {
        if (CompanyPolicy::query()->exists()) {
            return;
        }

        foreach ([
            ['Attendance and punctuality', "Clock in from My Attendance before your shift starts. A grace period applies; after it, the exact minutes late are deducted at your hourly rate (daily rate ÷ 8).\n\nIf you can't report for duty, send a notice on Report Absence as early as possible so Operations can send a reliever. Not reporting without notice (AWOL) is a serious offense."],
            ['Uniform and grooming', "Wear the complete prescribed uniform on duty, with your ID and license visible.\n\nKeep a neat haircut and a clean-shaven face, or a neatly trimmed moustache."],
            ['Code of conduct', "Treat clients, visitors and co-workers with respect. No sleeping, drinking or gambling on duty.\n\nReport incidents in the logbook and to your detachment commander right away."],
            ['Leave, overtime and borrowed load', "File leave on My Leave at least 5 days ahead, except for emergencies.\n\nOvertime is paid only when approved. Borrowed load is deducted from your next payslip after it is approved."],
        ] as $index => [$title, $body]) {
            $policy = new CompanyPolicy(['title' => $title, 'body' => $body, 'sort_order' => ($index + 1) * 10]);
            $policy->updated_by = $admin->id;
            $policy->save();
        }
    }
}
