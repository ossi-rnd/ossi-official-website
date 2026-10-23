<?php

namespace App\Enums;

/**
 * Every gate in the system. Each route requires one, and every write
 * re-checks it (see App\Livewire\Concerns\AuthorizesAbility).
 *
 * Viewing and changing are separate gates where a role may look but not
 * touch (the President or Vice President monitors; Accounting views the employee list).
 *
 * AppServiceProvider defines one Gate per case from roles(), so this match
 * is the single source of truth for "who can open what".
 */
enum Ability: string
{
    case AccessEmployerArea = 'access-employer-area';
    case SuperviseAttendance = 'supervise-attendance';
    case MonitorRequests = 'monitor-requests';
    case ReviewFieldLeave = 'review-field-leave';
    case ReviewOfficeLeave = 'review-office-leave';
    case EndorseGuardLeave = 'endorse-guard-leave';
    case ReviewOvertime = 'review-overtime';
    case ReviewOfficeOvertime = 'review-office-overtime';
    case ViewPeople = 'view-people';
    case ManagePeople = 'manage-people';
    case ViewPayRates = 'view-pay-rates';
    case DeployGuards = 'deploy-guards';
    case ManagePayroll = 'manage-payroll';
    case ConfigureSystem = 'configure-system';
    case ConfigureHolidays = 'configure-holidays';
    case ViewSystemLogs = 'view-system-logs';
    case PublishContent = 'publish-content';
    case ManageLoadRequests = 'manage-load-requests';
    case ApprovePayrollAdjustments = 'approve-payroll-adjustments';
    case ManageRelievers = 'manage-relievers';
    case ManageRoster = 'manage-roster';
    case ManagePatrols = 'manage-patrols';
    case MonitorOfficeAway = 'monitor-office-away';
    case ManageDocuments = 'manage-documents';
    case ManageTraining = 'manage-training';
    case HandleComplaints = 'handle-complaints';
    case HandleHrComplaints = 'handle-hr-complaints';
    case ManagePolicies = 'manage-policies';
    case ViewOrganization = 'view-organization';
    case ViewOfficeStaff = 'view-office-staff';
    case HandleResignations = 'handle-resignations';
    case HandleHrResignations = 'handle-hr-resignations';
    case PrepareFinalPay = 'prepare-final-pay';
    case SignFinalPay = 'sign-final-pay';
    case GiveMerits = 'give-merits';
    case ManageMeritTypes = 'manage-merit-types';
    case IssueNoticesToExplain = 'issue-notices-to-explain';
    case ApproveReimbursements = 'approve-reimbursements';
    case ViewReimbursements = 'view-reimbursements';
    case FileDeploymentClaims = 'file-deployment-claims';
    case ManageGuardPhones = 'manage-guard-phones';
    case ManageClients = 'manage-clients';
    case ManageOffices = 'manage-offices';
    case SeeBirthdays = 'see-birthdays';
    case ViewPettyCash = 'view-petty-cash';
    case ManagePettyCash = 'manage-petty-cash';
    case ManageRadios = 'manage-radios';
    case ManageReferrals = 'manage-referrals';

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return match ($this) {
            // The dashboard. Each section on it has its own Ability (what's waiting for
            // you, the people by area, today's attendance), so each role sees its part.
            self::AccessEmployerArea => [Role::Admin, Role::SuperAdmin, ...Role::leadership(), Role::Hr, Role::Accounting, Role::OperationsHq, Role::SatelliteOperations],
            // The attendance log (read-only).
            self::SuperviseAttendance => [Role::Admin, Role::Hr, Role::SuperAdmin, ...Role::leadership(), Role::OperationsHq, Role::SatelliteOperations],
            // The leave and overtime lists. Deciding needs one of the review gates below.
            self::MonitorRequests => [Role::Admin, Role::Hr, Role::SuperAdmin, ...Role::leadership(), Role::OperationsHq, Role::SatelliteOperations],
            // Guards' (field staff) leave: HR has the final say, after Operations endorses it.
            self::ReviewFieldLeave => [Role::Hr, Role::SuperAdmin],
            // Endorsing a guard's leave up the chain: the region's Satellite Operation, then Operation HQ.
            self::EndorseGuardLeave => [Role::SatelliteOperations, Role::OperationsHq],
            // Office staff's leave: the President or Vice President, not HR.
            self::ReviewOfficeLeave => [...Role::leadership(), Role::SuperAdmin],
            // Guards' overtime.
            self::ReviewOvertime => [Role::Hr, Role::Admin, Role::SuperAdmin, Role::OperationsHq, Role::SatelliteOperations],
            // Office staff's overtime: the President or Vice President, like their leave. Only they (and
            // the Super Admin) see office staff's leave and overtime requests at all.
            self::ReviewOfficeOvertime => [...Role::leadership(), Role::SuperAdmin],
            // The employee list and records, read-only. Editing needs ManagePeople.
            self::ViewPeople => [Role::Hr, Role::SuperAdmin, Role::Admin, Role::Accounting, ...Role::leadership(), Role::OperationsHq, Role::SatelliteOperations],
            self::ManagePeople => [Role::Hr, Role::SuperAdmin],
            // The pay rate on an employee record (Accounting sets it on Daily Rates).
            self::ViewPayRates => [Role::Hr, Role::Accounting, Role::SuperAdmin, ...Role::leadership()],
            // A guard's client, post and shift (Satellite Operation: in their region only).
            self::DeployGuards => [Role::Hr, Role::SuperAdmin, Role::SatelliteOperations],
            self::ManagePayroll => [Role::Accounting, Role::SuperAdmin],
            self::ConfigureSystem => [Role::SuperAdmin],
            self::ConfigureHolidays => [Role::SuperAdmin, Role::Admin],
            self::ViewSystemLogs => [Role::Developer, Role::SuperAdmin],
            self::PublishContent => [Role::Admin, Role::Hr, Role::SuperAdmin, ...Role::leadership()],
            self::ManageLoadRequests => [Role::Admin, Role::Accounting, Role::SuperAdmin],
            // The second person on large payroll adjustments; never the one who made it.
            self::ApprovePayrollAdjustments => [Role::SuperAdmin],
            // Absence notices and relievers (Satellite Operation: its region).
            self::ManageRelievers => [Role::OperationsHq, Role::SatelliteOperations, Role::SuperAdmin],
            // The Duty Roster and manual attendance (Satellite Operation: its region).
            self::ManageRoster => [Role::OperationsHq, Role::SatelliteOperations, Role::SuperAdmin],
            // Checkpoints, patrol rounds and guards away from the post (Satellite: its region).
            self::ManagePatrols => [Role::OperationsHq, Role::SatelliteOperations, Role::SuperAdmin],
            // Office staff out of the office: the President or Vice President, like their leave.
            // HR for the office staff it heads, Operation HQ for its Satellites (App\Support\Heads);
            // the President or VP see everyone's (and approve HR's).
            self::MonitorOfficeAway => [...Role::leadership(), Role::Hr, Role::OperationsHq, Role::SuperAdmin],
            // Company documents, licenses and client / legal documents.
            self::ManageDocuments => [Role::Admin, Role::SuperAdmin],
            self::ManageTraining => [Role::Admin, Role::SuperAdmin],
            // Complaints are confidential: HR (and Super Admin) only.
            self::HandleComplaints => [Role::Hr, Role::SuperAdmin],
            // ...except those filed by HR staff, which HR never sees: the President or Vice President's,
            // like HR's own leave and overtime.
            self::HandleHrComplaints => [...Role::leadership(), Role::SuperAdmin],
            // Writing the Company Policy page; everyone reads it.
            self::ManagePolicies => [Role::Admin, Role::Hr, Role::SuperAdmin],
            // The company's structure, from the President or Vice President down to the guards at each client.
            self::ViewOrganization => [...Role::leadership(), Role::SuperAdmin],
            // Office staff in the dashboard's numbers, charts and DTR. Operations (HQ and
            // Satellite) look after guards only, so theirs counts guards: all, or their region's.
            self::ViewOfficeStaff => [Role::SuperAdmin, ...Role::leadership(), Role::Admin, Role::Hr, Role::Accounting],
            // Resignations: accepting the last day and the clearance checklist. HR's job...
            self::HandleResignations => [Role::Hr, Role::SuperAdmin],
            // ...except an HR member's own, which the President or Vice President handles (like their leave and complaints).
            self::HandleHrResignations => [...Role::leadership(), Role::SuperAdmin],
            // Final pay: Accounting prepares the statement (and the cash bonds behind it)...
            self::PrepareFinalPay => [Role::Accounting, Role::SuperAdmin],
            // ...and Accounting and the President or Vice President sign it, they last (FinalPay::SIGNERS). Only they see the amounts. Super Admin can look, not sign.
            self::SignFinalPay => [Role::Accounting, ...Role::leadership(), Role::SuperAdmin],
            // Merits and demerits: whoever supervises the person (MeritRecord::canGive): Operations
            // for guards (a Satellite in its region), HR for anyone, the President or Vice President for HR staff.
            self::GiveMerits => [Role::OperationsHq, Role::SatelliteOperations, Role::Hr, ...Role::leadership(), Role::SuperAdmin],
            // The list of merit and demerit types and their points.
            self::ManageMeritTypes => [Role::Hr, Role::SuperAdmin],
            // Notices to Explain (due process): issued and decided by whoever supervises the person
            // (NoticeToExplain::canIssue): Operations for guards, HR for office staff, the President or Vice President
            // for HR staff. HR and the President or Vice President also read them all (canView).
            self::IssueNoticesToExplain => [Role::OperationsHq, Role::SatelliteOperations, Role::Hr, ...Role::leadership(), Role::SuperAdmin],
            // Office staff's reimbursements: the President or Vice President approves (like their leave)...
            self::ApproveReimbursements => [...Role::leadership(), Role::SuperAdmin],
            // ...and Accounting sees them all (it pays them on the next payslip).
            self::ViewReimbursements => [...Role::leadership(), Role::Accounting, Role::SuperAdmin],
            // Roadmap 5: Operations files a guard's deployment cost (a Satellite: its region's guards).
            self::FileDeploymentClaims => [Role::OperationsHq, Role::SatelliteOperations, Role::SuperAdmin],
            // A guard's company phone: told when they clock in on another one, and can make a
            // replacement phone theirs or forget the old one (a Satellite: its region's guards).
            self::ManageGuardPhones => [Role::OperationsHq, Role::SatelliteOperations, Role::Hr, Role::SuperAdmin],
            // Clients (the sites guards are deployed to) and their location and geofence: Operation HQ or
            // Admin sets them up before HR onboards a guard there. Satellite Operation, below HQ, doesn't.
            self::ManageClients => [Role::OperationsHq, Role::Admin, Role::SuperAdmin],
            // OSSI's own offices (head office, branches) where office staff clock in: company
            // premises, which Admin looks after. Operation HQ stays with clients.
            self::ManageOffices => [Role::Admin, Role::SuperAdmin],
            // Birthday reminders and "Birthdays this month": HR for everyone; Operations for guards
            // (a Satellite: its region's), as with other people matters of the guards they supervise.
            self::SeeBirthdays => [Role::Hr, Role::OperationsHq, Role::SatelliteOperations, Role::SuperAdmin],
            // Petty cash: every fund and its expenses, read-only (the President or Vice President monitors)...
            self::ViewPettyCash => [Role::Accounting, ...Role::leadership(), Role::SuperAdmin],
            // ...Accounting sets up the funds and releases replenishments. A fund's custodian keeps its
            // expenses without this (PettyCashFund::custodianOf).
            self::ManagePettyCash => [Role::Accounting, Role::SuperAdmin],
            // The NTC radio masterlist: Admin keeps company property and licenses, Operation HQ the
            // radios at the posts.
            self::ManageRadios => [Role::Admin, Role::OperationsHq, Role::SuperAdmin],
            // Referrals: HR links them to the hire (or says not hired), and sets the bonus and days.
            self::ManageReferrals => [Role::Hr, Role::SuperAdmin],
        };
    }

    /**
     * Route middleware string, e.g. "can:manage-payroll".
     */
    public function middleware(): string
    {
        return 'can:'.$this->value;
    }
}
