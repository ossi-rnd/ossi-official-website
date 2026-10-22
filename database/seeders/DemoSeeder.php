<?php

namespace Database\Seeders;

use App\Enums\DeductionType;
use App\Enums\EmploymentStatus;
use App\Enums\LeaveType;
use App\Enums\LoadRequestStatus;
use App\Enums\Position;
use App\Enums\RequestStatus;
use App\Enums\Requirement;
use App\Enums\RequirementStatus;
use App\Enums\Role;
use App\Enums\TimeLogStatus;
use App\Models\Announcement;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRate;
use App\Models\EmployeeRequirement;
use App\Models\Faq;
use App\Models\LeaveRequest;
use App\Models\LoadRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Local demo data. Every account's password is "password".
 *
 * Payroll is produced through the real Actions, so the seeded payslips are
 * computed exactly the way production ones are.
 */
class DemoSeeder extends Seeder
{
    private const STAFF = [
        ['Grace Tan', 'superadmin@example.test', [Role::SuperAdmin], '150301-OFF-0001', Position::AdminStaff],
        ['Noel Aquino', 'admin@example.test', [Role::Admin], '180115-OFF-0002', Position::AdminStaff],
        ['Erika Bautista', 'hr@example.test', [Role::Hr], '190601-OFF-0003', Position::HrStaff],
        ['Patrick Villareal', 'accounting@example.test', [Role::Accounting], '200210-OFF-0004', Position::AccountingStaff],
        ['Kevin Alonzo', 'developer@example.test', [Role::Developer], '210805-OFF-0005', Position::ItStaff],
    ];

    private const FIRST_NAMES = ['Ricardo', 'Jose', 'Liza', 'Antonio', 'Ferdinand', 'Romeo', 'Danilo', 'Arnel', 'Marlon', 'Eduardo', 'Cristina', 'Marites',
        'Rowena', 'Jocelyn', 'Renato', 'Rodel', 'Jerome', 'Ronaldo', 'Emmanuel', 'Nestor', 'Corazon', 'Divina', 'Josefina', 'Angelica', 'Maricel'];

    private const LAST_NAMES = ['Manalo', 'Mercado', 'Cruz', 'Reyes', 'Torres', 'Ramos', 'Dizon', 'Garcia', 'Gonzales', 'Del Rosario', 'Mendoza',
        'Castillo', 'Ocampo', 'Salazar', 'Pascual', 'Villanueva', 'Navarro', 'Domingo', 'Rivera', 'Aguilar', 'Tolentino', 'De Guzman', 'Pineda'];

    private const POSTS = [
        ['SM Megamall', 'Main Entrance'], ['SM Megamall', 'Parking'], ['BGC Tower One', 'Gate 1'], ['BGC Tower One', 'Lobby'],
        ['Ortigas CBD', 'Loading Dock'], ['Makati CBD', 'Roving'], ['Alabang Town Center', 'Parking'], ['Eastwood City', 'Gate 2'],
    ];

    public function run(AttendanceCalculator $calculator): void
    {
        mt_srand(2026); // Same demo data on every machine.

        $dayShift = Shift::query()->where('is_default', true)->firstOrFail();
        $nightShift = Shift::query()->where('name', 'Night Shift')->firstOrFail();

        $superAdmin = null;

        foreach (self::STAFF as [$name, $email, $roles, $number, $position]) {
            $user = $this->account($name, $email, $roles);
            // The Super Admin is a system account: no HR record (Role::withoutEmployeeRecord).
            if (array_filter($roles, fn (Role $role) => in_array($role, Role::withoutEmployeeRecord(), true)) === []) {
                EmployeeProfile::factory()->claimedBy($user)->officeStaff()->create(['employee_number' => $number, 'position' => $position]);
            }
            $superAdmin ??= $user;
        }

        $guards = collect();
        $maria = $this->account('Maria Santos', 'employee@example.test', [Role::Employee]);
        EmployeeProfile::factory()->claimedBy($maria)->create(['employee_number' => '240601-SEC-1001', 'detachment' => 'SM Megamall', 'post' => 'Main Entrance', 'shift_id' => $dayShift->id]);
        $guards->push($maria);

        for ($i = 2; $i <= 24; $i++) {
            [$detachment, $post] = self::POSTS[$i % count(self::POSTS)];
            $isNight = $i % 6 === 0;
            $name = self::FIRST_NAMES[$i % count(self::FIRST_NAMES)].' '.self::LAST_NAMES[($i * 7) % count(self::LAST_NAMES)];
            $user = $this->account($name, "guard{$i}@example.test", [Role::Employee]);

            EmployeeProfile::factory()->claimedBy($user)->create([
                'employee_number' => sprintf('240601-SEC-%04d', 1000 + $i),
                'detachment' => $detachment,
                'post' => $post,
                'position' => $i % 8 === 0 ? Position::ShiftInCharge : Position::SecurityGuard,
                'shift_id' => $isNight ? $nightShift->id : $dayShift->id,
                'employment_status' => $i % 5 === 0 ? EmploymentStatus::Probationary : EmploymentStatus::Regular,
                'sss_number' => sprintf('34-%07d-%d', 1000000 + $i, $i % 10),
                'tin_number' => sprintf('123-456-%03d', 700 + $i),
            ]);
            $guards->push($user);
        }

        // HR has added these people, but they haven't registered: try /register with EMP-3001 + juan.delacruz@example.test.
        EmployeeProfile::factory()->create(['employee_number' => '250115-SEC-3001', 'name' => 'Juan Dela Cruz', 'email' => 'juan.delacruz@example.test', 'shift_id' => $dayShift->id]);
        foreach (['Ana Lim', 'Paolo Santiago', 'Grace Uy'] as $index => $name) {
            EmployeeProfile::factory()->create(['employee_number' => '250115-SEC-300'.($index + 2), 'name' => $name, 'email' => 'unregistered'.($index + 2).'@example.test', 'shift_id' => $dayShift->id]);
        }

        // Probation ends in 10 days: run `php artisan app:send-probation-alerts` to see HR's alert.
        EmployeeProfile::query()->where('employee_number', '240601-SEC-1005')->update([
            'employment_status' => EmploymentStatus::Probationary,
            'hire_date' => CarbonImmutable::today()->addDays(10)->subMonthsNoOverflow((int) config('hr.probation.months'))->toDateString(),
        ]);

        // Before payroll is generated, so the demo payslips use these rates.
        $this->call(ClientSeeder::class); // Region → Province → Client, linked to the guards' detachments.
        $this->call(OperationsAccountsSeeder::class); // Executive, Operation HQ, Satellite Operation (Region IV-A).
        $this->rates();
        $this->attendance($guards, $calculator);
        $this->requests($guards, $superAdmin);
        $this->call(PayrollSampleSeeder::class); // Rates, deductions, adjustments and payslips.
        $this->content($superAdmin);
        $this->requirements($maria, $superAdmin);
        $this->load($maria, $superAdmin);
        $this->call(RosterSeeder::class); // Two weeks of 9-hour duties from today.
        $this->call(PatrolSeeder::class); // Checkpoints at the roving posts' sites.
        $this->call(OperationsSampleSeeder::class); // Relievers, documents, trainings, complaints, policies.
    }

    /**
     * Three of Maria's load-on-credit requests: one approved (a Load deduction
     * waiting for her next payslip), one waiting, one rejected.
     */
    private function load(User $maria, ?User $admin): void
    {
        foreach ([
            ['globe', 100, LoadRequestStatus::Approved, 5],
            ['smart', 50, LoadRequestStatus::Pending, 0],
            ['globe', 300, LoadRequestStatus::Rejected, 2],
        ] as [$network, $amount, $status, $daysAgo]) {
            $at = CarbonImmutable::now()->subDays($daysAgo)->subHours(2);
            $loadRequest = new LoadRequest;
            $loadRequest->forceFill([
                'user_id' => $maria->id,
                'network' => $network,
                'mobile_number' => '09171234567',
                'amount' => $amount,
                'status' => $status,
                'rejected_by' => $status === LoadRequestStatus::Rejected ? $admin?->id : null,
                'rejected_at' => $status === LoadRequestStatus::Rejected ? $at->addHour() : null,
                'rejection_reason' => $status === LoadRequestStatus::Rejected ? 'Number not reachable. Please check it and request again.' : null,
                'created_at' => $at,
                'updated_at' => $at,
            ])->save();

            if ($status === LoadRequestStatus::Approved) {
                $deduction = new EmployeeDeduction(['label' => $loadRequest->deductionLabel(), 'type' => DeductionType::Load, 'installment_amount' => $amount, 'original_amount' => $amount]);
                $deduction->user()->associate($maria);
                $deduction->remaining_balance = (string) $amount;
                $deduction->save();

                $loadRequest->forceFill(['approved_by' => $admin?->id, 'approved_at' => $at->addHour(), 'employee_deduction_id' => $deduction->id])->save();
            }
        }
    }

    /**
     * Maria's 201 file shows every state: on file, expiring soon, awaiting
     * HR's check, and promised but overdue. Run `php artisan
     * app:send-requirement-alerts` to see the alerts.
     */
    private function requirements(User $maria, ?User $hr): void
    {
        $profile = $maria->employeeProfile()->firstOrFail();
        $today = CarbonImmutable::today();
        $disk = Storage::disk((string) config('hr.documents.disk'));

        $states = [
            [Requirement::PersonalDataSheet, RequirementStatus::OnFile, []],
            [Requirement::DataPrivacyAcknowledgement, RequirementStatus::OnFile, []],
            [Requirement::Picture2x2, RequirementStatus::OnFile, []],
            [Requirement::SssE1, RequirementStatus::OnFile, []],
            [Requirement::TinId, RequirementStatus::OnFile, []],
            [Requirement::SecurityLicense, RequirementStatus::OnFile, ['expires_on' => $today->addYear()->toDateString()]],
            [Requirement::NbiClearance, RequirementStatus::OnFile, ['expires_on' => $today->addDays(20)->toDateString()]],
            [Requirement::BirthCertificate, RequirementStatus::Submitted, []],
            [Requirement::DrugTest, RequirementStatus::Missing, ['promised_on' => $today->subDays(2)->toDateString()]],
        ];

        foreach ($states as [$requirement, $status, $extra]) {
            $file = [];

            if ($status !== RequirementStatus::Missing) {
                $path = sprintf('201/%d/%s-demo.pdf', $profile->id, $requirement->value);
                $disk->put($path, "%PDF-1.4\n% Demo document: {$requirement->label()}\n%%EOF\n");
                $file = ['file_path' => $path, 'file_name' => str($requirement->value)->slug().'.pdf', 'file_mime' => 'application/pdf', 'file_size' => 64];
            }

            $record = new EmployeeRequirement;
            $record->forceFill([
                'employee_profile_id' => $profile->id,
                'requirement' => $requirement,
                'status' => $status,
                ...$file,
                ...$extra,
                'submitted_by' => $status !== RequirementStatus::Missing ? $maria->id : null,
                'submitted_at' => $status !== RequirementStatus::Missing ? $today->subDays(10) : null,
                'verified_by' => $status === RequirementStatus::OnFile ? $hr?->id : null,
                'verified_at' => $status === RequirementStatus::OnFile ? $today->subDays(9) : null,
            ])->save();
        }
    }

    /**
     * A personal rate only for exceptions (Maricel, an experienced
     * shift-in-charge, is paid more). PayrollSampleSeeder sets the company
     * rate per position.
     */
    private function rates(): void
    {
        $maricel = EmployeeProfile::query()->where('name', 'Maricel Garcia')->first();

        if ($maricel !== null) {
            $rate = new EmployeeRate;
            $rate->forceFill(['employee_profile_id' => $maricel->id, 'daily_rate' => 850, 'effective_from' => '2025-07-17', 'note' => 'Senior shift-in-charge'])->save();
        }
    }

    /**
     * Written directly, not through SaveAnnouncement: demo data shouldn't queue notifications.
     */
    private function content(?User $author): void
    {
        $announcement = new Announcement([
            'title' => 'Welcome to the new attendance system',
            'body' => "Clock in and out from My Attendance with your selfie and location.\nQuestions? Open Help & FAQs in the menu.",
            'starts_at' => CarbonImmutable::now()->subDay(),
        ]);
        $announcement->created_by = $author?->id;
        $announcement->save();

        foreach ([
            ['How do I clock in?', "Open My Attendance and tap Clock In. Allow the camera and location when your browser asks.\nYour selfie and GPS position are saved with the time."],
            ['Why does my payslip say "Estimate"?', 'Until Accounting generates your payslip, the figures are calculated live from your attendance so far. They become final once generated.'],
            ['How is the late deduction computed?', 'Minutes after your shift start plus the grace period are added up for the pay period. The total falls into a late bracket that sets the deduction. Open "Show days" on your payslip to see every late day.'],
            ['Where do I file leave or overtime?', 'Use My Leave or My Overtime. You can see the date each request was filed and its status.'],
            ['Who do I contact about a payroll error?', 'Contact Accounting. Corrections are added to the next payroll, so a past payslip is never changed.'],
        ] as $index => [$question, $answer]) {
            Faq::create(['question' => $question, 'answer' => $answer, 'sort_order' => ($index + 1) * 10]);
        }
    }

    /**
     * @param  list<Role>  $roles
     */
    private function account(string $name, string $email, array $roles): User
    {
        return User::factory()->withRoles(...$roles)->create(['name' => $name, 'email' => $email]);
    }

    /**
     * About 40 days of history: mostly on time, some late, a few absences.
     *
     * @param  Collection<int, User>  $guards
     */
    private function attendance($guards, AttendanceCalculator $calculator): void
    {
        $today = CarbonImmutable::today();

        foreach ($guards as $guard) {
            $guard->load('employeeProfile.shift');
            $shift = $guard->employeeProfile->shift ?? Shift::fallback();

            for ($daysAgo = 40; $daysAgo >= 1; $daysAgo--) {
                $date = $today->subDays($daysAgo);

                if ($date->isSunday() || mt_rand(1, 100) <= 6) {
                    continue; // Day off or absent.
                }

                [$start, $end] = $calculator->shiftWindow($shift, $date);
                $roll = mt_rand(1, 100);
                $clockIn = $start->addMinutes($roll <= 75 ? -mt_rand(0, 15) : mt_rand(11, 45));
                $clockOut = $end->addMinutes($roll % 9 === 0 ? mt_rand(60, 150) : mt_rand(0, 10));

                $late = $calculator->lateMinutes($clockIn, $shift, $date);
                $undertime = $calculator->undertimeMinutes($clockOut, $shift, $date);

                $log = new TimeLog;
                $log->forceFill([
                    'user_id' => $guard->id,
                    'shift_id' => $shift->id,
                    'log_date' => $date->toDateString(),
                    'clock_in_at' => $clockIn,
                    'clock_out_at' => $clockOut,
                    'late_minutes' => $late,
                    'undertime_minutes' => $undertime,
                    'overtime_minutes' => $calculator->overtimeMinutes($clockIn, $clockOut, $shift),
                    'status' => TimeLogStatus::fromMinutes($late, $undertime, true),
                ])->save();
            }
        }
    }

    /**
     * @param  Collection<int, User>  $guards
     */
    private function requests($guards, User $reviewer): void
    {
        foreach ($guards->take(6) as $index => $guard) {
            $start = CarbonImmutable::today()->addDays(3 + $index * 2);
            $leave = new LeaveRequest(['type' => LeaveType::Vacation, 'start_date' => $start, 'end_date' => $start->addDay(), 'reason' => 'Family event in the province.']);
            $leave->user()->associate($guard);
            $leave->save();
        }

        // Approved overtime for the long days already logged, so payroll shows real OT pay.
        TimeLog::query()->where('overtime_minutes', '>=', 30)->get()->each(function (TimeLog $log) use ($reviewer) {
            $overtime = new OvertimeRequest(['date' => $log->log_date, 'post' => null, 'requested_minutes' => $log->overtime_minutes, 'reason' => 'Relief guard arrived late.']);
            $overtime->user()->associate($log->user_id);
            $overtime->supervisor()->associate($reviewer);
            $overtime->forceFill([
                'status' => RequestStatus::Approved,
                'approved_minutes' => min($log->overtime_minutes, 120),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $log->log_date->addDay(),
            ])->save();
        });
    }
}
