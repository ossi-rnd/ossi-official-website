<?php

namespace App\Support;

use App\Enums\Ability;
use App\Enums\ComplaintStatus;
use App\Enums\LoadRequestStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\ReferralStatus;
use App\Enums\ReplenishmentStatus;
use App\Enums\ResignationStatus;
use App\Models\AbsenceNotice;
use App\Models\AwayLog;
use App\Models\CompanyDocument;
use App\Models\Complaint;
use App\Models\EmployeeProfile;
use App\Models\FinalPay;
use App\Models\LeaveRequest;
use App\Models\LoadRequest;
use App\Models\NoticeToExplain;
use App\Models\OvertimeRequest;
use App\Models\PayrollConcern;
use App\Models\PayrollRun;
use App\Models\PettyCashFund;
use App\Models\PettyCashReplenishment;
use App\Models\Radio;
use App\Models\Referral;
use App\Models\Reimbursement;
use App\Models\Resignation;
use App\Models\TrainingRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The sidebar, as data. Each item names the Ability that opens its page, so
 * the menu can never show a link the route would refuse, and adding a page
 * is one line here instead of another hand-written <a> in the layout.
 *
 * @phpstan-type NavItem array{label: string, route: string, icon: string, active: string, badge: int, tone: string}
 * @phpstan-type NavSection array{title: string, items: list<NavItem>}
 */
final class Navigation
{
    /**
     * @return list<NavSection>
     */
    public static function for(User $user): array
    {
        $can = fn (Ability $ability): bool => $user->can($ability->value);
        $user->loadMissing('employeeProfile');
        $onPayroll = ! $user->isPaidOutsidePayroll();

        $sections = [
            ['title' => 'Overview', 'items' => array_values(array_filter([
                $can(Ability::AccessEmployerArea) ? self::item('Dashboard', 'dashboard', 'grid') : null,
                $can(Ability::ViewOrganization) ? self::item('Organization', 'organization', 'building') : null,
            ]))],
            ['title' => 'Management', 'items' => array_values(array_filter([
                $can(Ability::ManagePayroll) ? self::item('Payroll', 'payroll', 'banknotes', badge: PayrollRun::query()->where('status', PayrollRunStatus::Processing)->count()) : null,
                // Office staff's reimbursements; the badge (what to approve) is the President or Vice President's.
                $can(Ability::ViewReimbursements) ? self::item('Reimbursements', 'reimbursement-requests', 'banknotes', badge: $can(Ability::ApproveReimbursements) ? Reimbursement::query()->pending()->where('user_id', '!=', $user->id)->count() : 0) : null,
                // Employees' "Report a problem" on a payslip, until answered.
                $can(Ability::ManagePayroll) ? self::item('Payroll Concerns', 'payroll-concerns', 'flag', badge: PayrollConcern::query()->open()->count()) : null,
                $can(Ability::ManagePayroll) ? self::item('13th Month Pay', 'thirteenth-month', 'gift') : null,
                $can(Ability::ManagePayroll) ? self::item('Daily Rates', 'daily-rates', 'banknotes', badge: EmployeeProfile::query()->paidHere()->withoutRate()->count()) : null,
                $can(Ability::ViewPeople) ? self::item('Employees', 'employees', 'users', active: 'employees*') : null,
                $can(Ability::SuperviseAttendance) ? self::item('Attendance Log', 'attendance-log', 'map-pin') : null,
                // Badges count what's waiting for YOUR decision (office staff's go to the President or Vice President).
                $can(Ability::MonitorRequests) ? self::item('Leave Requests', 'leave-requests', 'clipboard', badge: LeaveRequest::query()->awaitingActionBy($user)->count()) : null,
                $can(Ability::MonitorRequests) ? self::item('Overtime Requests', 'overtime-requests', 'clock-plus', badge: OvertimeRequest::query()->awaitingReviewBy($user)->count()) : null,
                $can(Ability::ManagePayroll) ? self::item('Late Deduction', 'late-deductions', 'clock-alert') : null,
                $can(Ability::ManagePayroll) ? self::item('Tax & Contributions', 'tax-settings', 'scroll') : null,
                // The sites guards are deployed to: set up by Operation HQ before HR onboards guards there.
                $can(Ability::ManageClients) ? self::item('Clients', 'clients', 'building') : null,
                // OSSI's own offices, where office staff clock in.
                $can(Ability::ManageOffices) ? self::item('OSSI Offices', 'offices', 'building') : null,
                $can(Ability::ManageRoster) ? self::item('Duty Roster', 'roster', 'calendar') : null,
                $can(Ability::ManagePatrols) ? self::item('Patrols', 'patrols', 'shield', badge: AwayLog::query()->open()->whereHas('user', fn ($query) => $query->visibleTo($user)->whereHas('employeeProfile', fn ($profile) => $profile->where('is_office_staff', false)))->count()) : null,
                $can(Ability::MonitorOfficeAway) ? self::item('Office Away', 'office-away', 'map-pin', badge: AwayLog::query()->open()->whereHas('user', fn ($query) => $query->where(fn ($who) => $who->whereDoesntHave('employeeProfile')->orWhereHas('employeeProfile', fn ($profile) => $profile->where('is_office_staff', true))))->count()) : null,
                $can(Ability::ManageRelievers) ? self::item('Relievers', 'relievers', 'arrows-right-left', badge: AbsenceNotice::query()->needsReliever()->visibleTo($user)->count()) : null,
                // What guards paid for a deployment, claimed by Operations (roadmap 5).
                $can(Ability::FileDeploymentClaims) ? self::item('Deployment Costs', 'deployment-claims', 'banknotes') : null,
                Complaint::handlesAny($user) ? self::item('Complaints', 'complaint-cases', 'flag', badge: Complaint::query()->handledBy($user)->where('status', ComplaintStatus::Open)->count()) : null,
                Resignation::handlesAny($user) ? self::item('Resignations', 'resignations', 'logout', badge: Resignation::query()->handledBy($user)->where('status', ResignationStatus::Pending)->count()) : null,
                $can(Ability::SignFinalPay) ? self::item('Final Pay', 'final-pay', 'banknotes', badge: FinalPay::waitingFor($user)) : null,
                $can(Ability::PrepareFinalPay) ? self::item('Cash Bonds', 'cash-bonds', 'lock') : null,
                // Every fund; the badge (replenishments to release) is Accounting's.
                $can(Ability::ViewPettyCash) ? self::item('Petty Cash', 'petty-cash', 'wallet', badge: $can(Ability::ManagePettyCash) ? PettyCashReplenishment::query()->where('status', ReplenishmentStatus::Pending)->where('requested_by', '!=', $user->id)->count() : 0) : null,
                $can(Ability::ManageDocuments) ? self::item('Documents', 'documents', 'folder', badge: CompanyDocument::query()->current()->whereNotNull('expires_on')->whereDate('expires_on', '<=', today()->addDays(CompanyDocument::DUE_SOON_DAYS)->toDateString())->count()) : null,
                // Badge: guards' training requests to answer.
                $can(Ability::ManageTraining) ? self::item('Training', 'trainings', 'academic-cap', badge: TrainingRequest::query()->pending()->count()) : null,
                // Badge: radios whose NTC permit expires within 30 days, or has.
                $can(Ability::ManageRadios) ? self::item('Radios', 'radios', 'radio', badge: Radio::query()->permitDueBy(CarbonImmutable::today()->addDays(Radio::DUE_SOON_DAYS))->count()) : null,
                // Badge: referrals waiting for HR to say hired or not.
                $can(Ability::ManageReferrals) ? self::item('Referrals', 'referral-cases', 'user-plus', badge: Referral::query()->where('status', ReferralStatus::Submitted)->count()) : null,
                $can(Ability::GiveMerits) ? self::item('Merits & Demerits', 'merits', 'scale') : null,
                // Badge: the ones you issued that are ready to decide (explained, or the days passed).
                $can(Ability::IssueNoticesToExplain) ? self::item('Notices to Explain', 'notices-to-explain', 'scroll', badge: NoticeToExplain::query()->where('issued_by', $user->id)->get(['id', 'status', 'explain_by'])->filter(fn (NoticeToExplain $notice) => $notice->readyToDecide())->count()) : null,
                $can(Ability::ManageLoadRequests) ? self::item('Load Requests', 'load-requests', 'phone', badge: LoadRequest::query()->where('status', LoadRequestStatus::Pending)->count()) : null,
                $can(Ability::PublishContent) ? self::item('Announcements', 'announcements', 'megaphone') : null,
            ]))],
            ['title' => 'System', 'items' => array_values(array_filter([
                $can(Ability::ConfigureHolidays) ? self::item('Holidays', 'holidays', 'calendar') : null,
                $can(Ability::ConfigureSystem) ? self::item('Shifts', 'shifts', 'sun-moon') : null,
                $can(Ability::ConfigureSystem) ? self::item('Roles & Access', 'roles', 'shield') : null,
                $can(Ability::ViewSystemLogs) ? self::item('Audit Log', 'audit-log', 'scroll') : null,
            ]))],
            // Paid outside this payroll (the President or Vice President): no attendance, leave, pay or load of their own here.
            ['title' => 'Personal', 'items' => array_values(array_filter([
                $onPayroll ? self::item('My Attendance', 'attendance', 'clock') : null,
                // Guards at a post tell Operations when they can't report; office staff use My Leave.
                $onPayroll && $user->employeeProfile?->hasDeployment() === true ? self::item('Report Absence', 'absence-notice', 'arrows-right-left') : null,
                $onPayroll ? self::item('My Leave', 'leave', 'calendar') : null,
                $onPayroll ? self::item('My Overtime', 'overtime', 'clock-plus') : null,
                $onPayroll ? self::item('My Payslips', 'payslips', 'banknotes') : null,
                // Loans, cash advances and the cash bond: what's left and what each payslip took.
                $onPayroll ? self::item('My Loans', 'loans', 'clipboard') : null,
                // Guards: ask for a refresher, license-renewal training, first aid.
                $onPayroll && $user->employeeProfile?->hasDeployment() === true ? self::item('My Training', 'my-training', 'academic-cap') : null,
                $onPayroll ? self::item('Borrow Load', 'load', 'phone') : null,
                // Office staff: money spent for the company. A guard: once Operations claimed a deployment cost for them.
                $onPayroll && Reimbursement::canSeeOwn($user) ? self::item('My Reimbursements', 'reimbursements', 'banknotes') : null,
                // A fund's custodian who doesn't already have Management → Petty Cash.
                ! $can(Ability::ViewPettyCash) && PettyCashFund::custodianOf($user) !== null ? self::item('Petty Cash', 'petty-cash', 'wallet') : null,
                $onPayroll && Referral::canRefer($user) ? self::item('Refer a Friend', 'referrals', 'user-plus') : null,
                $onPayroll ? self::item('My Complaints', 'complaints', 'flag') : null,
                // Only while one is in progress; filing starts from My Profile (not a daily menu item).
                $onPayroll && $user->resignations()->open()->exists() ? self::item('My Resignation', 'resignation', 'logout') : null,
                // Only for someone who has one; badge: to mark received, or still to explain.
                ($myNotices = NoticeToExplain::query()->where('user_id', $user->id)->get(['id', 'status', 'explain_by', 'acknowledged_at', 'decision_acknowledged_at']))->isNotEmpty()
                    ? self::item('My Notices', 'my-notices', 'scroll', badge: $myNotices->filter(fn (NoticeToExplain $notice) => $notice->canStillExplain() || ($notice->isDecided() ? $notice->decision_acknowledged_at === null : $notice->acknowledged_at === null))->count())
                    : null,
                // Publishers already have Management → Announcements (with all of them).
                $can(Ability::PublishContent) ? null : self::item('Announcements', 'news', 'megaphone'),
                self::item('Company Policy', 'company-policy', 'book-open'),
                self::item('Help & FAQs', 'help', 'question'),
            ]))],
        ];

        return array_values(array_filter($sections, fn (array $section) => $section['items'] !== []));
    }

    /**
     * @return NavItem
     */
    private static function item(string $label, string $route, string $icon, int $badge = 0, ?string $active = null): array
    {
        return [
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'active' => $active ?? $route,
            'badge' => $badge,
            'tone' => 'amber',
        ];
    }
}
