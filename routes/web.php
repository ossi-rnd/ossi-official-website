<?php

use App\Enums\Ability;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AcknowledgePrivacyNoticeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrintCheckpointsController;
use App\Http\Controllers\PrivacyNoticeController;
use App\Http\Controllers\ReverseGeocodeController;
use App\Http\Controllers\SessionKeepAliveController;
use App\Http\Controllers\ShowAvatarController;
use App\Http\Controllers\ShowCompanyDocumentController;
use App\Http\Controllers\ShowComplaintFileController;
use App\Http\Controllers\ShowEquipmentPhotoController;
use App\Http\Controllers\ShowLeaveDocumentController;
use App\Http\Controllers\ShowLoadProofController;
use App\Http\Controllers\ShowPatrolScanPhotoController;
use App\Http\Controllers\ShowPayrollConcernAttachmentController;
use App\Http\Controllers\ShowPettyCashReceiptController;
use App\Http\Controllers\ShowPolicyFileController;
use App\Http\Controllers\ShowPunchPhotoController;
use App\Http\Controllers\ShowReimbursementReceiptController;
use App\Http\Controllers\ShowRequirementDocumentController;
use App\Http\Middleware\EnsureOnPayroll;
use App\Livewire\Admin\Announcements;
use App\Livewire\Admin\AttendanceDashboard;
use App\Livewire\Admin\AttendanceLog;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\AwayMonitor;
use App\Livewire\Admin\CashBonds;
use App\Livewire\Admin\Clients;
use App\Livewire\Admin\CompanyDocuments;
use App\Livewire\Admin\Complaints;
use App\Livewire\Admin\DailyRates;
use App\Livewire\Admin\DeploymentClaims;
use App\Livewire\Admin\DutyRoster;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\Employees;
use App\Livewire\Admin\Equipment;
use App\Livewire\Admin\FinalPays;
use App\Livewire\Admin\Holidays;
use App\Livewire\Admin\LateDeductionSettings;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Admin\LoadRequests;
use App\Livewire\Admin\Merits;
use App\Livewire\Admin\NoticesToExplain;
use App\Livewire\Admin\Offices;
use App\Livewire\Admin\Organization;
use App\Livewire\Admin\OvertimeRequests;
use App\Livewire\Admin\Patrols;
use App\Livewire\Admin\Payroll;
use App\Livewire\Admin\PayrollConcerns;
use App\Livewire\Admin\Radios;
use App\Livewire\Admin\ReferralCases;
use App\Livewire\Admin\ReimbursementRequests;
use App\Livewire\Admin\Relievers;
use App\Livewire\Admin\Resignations;
use App\Livewire\Admin\RolesAccess;
use App\Livewire\Admin\Shifts;
use App\Livewire\Admin\TaxSettings;
use App\Livewire\Admin\ThirteenthMonth;
use App\Livewire\Admin\Trainings;
use App\Livewire\AnnouncementBoard;
use App\Livewire\Attendance\ClockPanel;
use App\Livewire\Attendance\ReportAbsence;
use App\Livewire\CompanyPolicies;
use App\Livewire\Complaints\MyComplaints;
use App\Livewire\Equipment\MyEquipment;
use App\Livewire\Finance\PettyCash;
use App\Livewire\Help;
use App\Livewire\Leave\MyLeave;
use App\Livewire\Load\BuyLoad;
use App\Livewire\Notices\MyNotices;
use App\Livewire\Overtime\MyOvertime;
use App\Livewire\Payroll\MyLoans;
use App\Livewire\Payroll\MyPayslips;
use App\Livewire\Profile\ShowProfile;
use App\Livewire\Referrals\MyReferrals;
use App\Livewire\Reimbursements\MyReimbursements;
use App\Livewire\Resignations\MyResignation;
use App\Livewire\Training\MyTraining;
use Illuminate\Support\Facades\Route;

// Auth routes (login, register, password reset, email verification, 2FA, logout)
// are registered by Fortify. See config/fortify.php and FortifyServiceProvider.

Route::redirect('/', '/home');

// Public on purpose: shown before registration collects any personal data (RA 10173 sec. 16(a)).
Route::get('/privacy-notice', PrivacyNoticeController::class)->name('privacy-notice');

Route::middleware('auth')->group(function () {
    Route::post('/privacy-notice', AcknowledgePrivacyNoticeController::class)->name('privacy-notice.acknowledge');

    // Called by the idle-timeout script, which also runs on the verify-email page.
    Route::post('/session/keep-alive', SessionKeepAliveController::class)
        ->middleware('throttle:session-keep-alive')
        ->name('session.keep-alive');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/home', HomeController::class)->name('home');

    // Employee self-service: every signed-in user...
    Route::get('/profile', ShowProfile::class)->name('profile');
    Route::get('/account', AccountSettingsController::class)->name('account-settings');
    Route::get('/help', Help::class)->name('help');
    Route::get('/news', AnnouncementBoard::class)->name('news');
    Route::get('/company-policy', CompanyPolicies::class)->name('company-policy');
    // Anyone may receive a Notice to Explain; the page lists only their own.
    Route::get('/my-notices', MyNotices::class)->name('my-notices');

    // ...and, for those paid through this payroll (not the Executive), attendance and pay.
    Route::middleware(EnsureOnPayroll::class)->group(function () {
        Route::get('/attendance', ClockPanel::class)->name('attendance');
        Route::get('/leave', MyLeave::class)->name('leave');
        Route::get('/overtime', MyOvertime::class)->name('overtime');
        Route::get('/payslips', MyPayslips::class)->name('payslips');
        Route::get('/my-loans', MyLoans::class)->name('loans');
        // Guards (the page checks): ask for a training, see their sessions.
        Route::get('/my-training', MyTraining::class)->name('my-training');
        Route::get('/load', BuyLoad::class)->name('load');
        Route::get('/load/{loadRequest}/receipt', ShowLoadProofController::class)->name('load.receipt');
        Route::get('/report-absence', ReportAbsence::class)->name('absence-notice');
        Route::get('/complaints', MyComplaints::class)->name('complaints');
        Route::get('/resignation', MyResignation::class)->name('resignation');
        // Office staff only (the page checks Reimbursement::canFile).
        Route::get('/reimbursements', MyReimbursements::class)->name('reimbursements');
        // Refer someone for hiring (hiring perks); the bonus comes on the payslip.
        Route::get('/referrals', MyReferrals::class)->name('referrals');
        // Uniforms, radios and gear issued to me; requests; damage or loss reports to explain.
        Route::get('/my-equipment', MyEquipment::class)->name('my-equipment');
    });
    Route::get('/company-policy/{policy}/file', ShowPolicyFileController::class)->name('company-policy.file');

    Route::get('/attendance/reverse-geocode', ReverseGeocodeController::class)
        ->middleware('throttle:reverse-geocode')
        ->name('attendance.reverse-geocode');

    // Private files, streamed after a policy check.
    Route::get('/punches/{punch}/photo', ShowPunchPhotoController::class)->name('punches.photo');
    Route::get('/patrol-scans/{scan}/photo', ShowPatrolScanPhotoController::class)->name('patrol-scans.photo');
    Route::get('/avatars/{user}', ShowAvatarController::class)->name('avatars.show');
    Route::get('/requirements/{requirement}/document', ShowRequirementDocumentController::class)->name('requirements.document');
    Route::get('/payroll-concerns/{concern}/attachment', ShowPayrollConcernAttachmentController::class)->name('payroll-concerns.attachment');
    Route::get('/reimbursements/{reimbursement}/receipt', ShowReimbursementReceiptController::class)->name('reimbursements.receipt');
    Route::get('/leave-documents/{document}', ShowLeaveDocumentController::class)->name('leave.document');

    // Staff pages: one gate each. Livewire re-checks `can:` on every request,
    // and every write re-checks again inside the component.
    Route::get('/dashboard', AttendanceDashboard::class)->middleware(Ability::AccessEmployerArea->middleware())->name('dashboard');

    Route::get('/attendance-log', AttendanceLog::class)->middleware(Ability::SuperviseAttendance->middleware())->name('attendance-log');

    // Lists for everyone who monitors; deciding re-checks the review gates.
    Route::middleware(Ability::MonitorRequests->middleware())->group(function () {
        Route::get('/leave-requests', LeaveRequests::class)->name('leave-requests');
        Route::get('/overtime-requests', OvertimeRequests::class)->name('overtime-requests');
    });

    // Read-only for most; editing re-checks manage-people (or deploy-guards).
    Route::middleware(Ability::ViewPeople->middleware())->group(function () {
        Route::get('/employees', Employees::class)->name('employees');
        Route::get('/employees/{profile}', EmployeeDetail::class)->name('employees.show');
    });

    Route::middleware(Ability::ManagePayroll->middleware())->group(function () {
        Route::get('/payroll', Payroll::class)->name('payroll');
        Route::get('/late-deductions', LateDeductionSettings::class)->name('late-deductions');
        Route::get('/tax-settings', TaxSettings::class)->name('tax-settings');
        Route::get('/thirteenth-month', ThirteenthMonth::class)->name('thirteenth-month');
        Route::get('/daily-rates', DailyRates::class)->name('daily-rates');
        Route::get('/payroll-concerns', PayrollConcerns::class)->name('payroll-concerns');
    });

    Route::middleware(Ability::ConfigureSystem->middleware())->group(function () {
        Route::get('/shifts', Shifts::class)->name('shifts');
        Route::get('/roles', RolesAccess::class)->name('roles');
    });

    // Operation HQ sets up a client before HR onboards guards to it.
    Route::get('/clients', Clients::class)->middleware(Ability::ManageClients->middleware())->name('clients');
    // OSSI's own offices, where office staff clock in: Admin and Super Admin.
    Route::get('/offices', Offices::class)->middleware(Ability::ManageOffices->middleware())->name('offices');

    Route::get('/holidays', Holidays::class)->middleware(Ability::ConfigureHolidays->middleware())->name('holidays');

    Route::get('/load-requests', LoadRequests::class)->middleware(Ability::ManageLoadRequests->middleware())->name('load-requests');

    Route::get('/announcements', Announcements::class)->middleware(Ability::PublishContent->middleware())->name('announcements');

    Route::get('/roster', DutyRoster::class)->middleware(Ability::ManageRoster->middleware())->name('roster');

    Route::middleware(Ability::ManagePatrols->middleware())->group(function () {
        Route::get('/patrols', Patrols::class)->name('patrols');
        Route::get('/patrols/{client}/print', PrintCheckpointsController::class)->name('patrols.print');
    });

    // Office staff out of the office: the Executive watches, like their leave.
    Route::get('/office-away', AwayMonitor::class)->middleware(Ability::MonitorOfficeAway->middleware())->name('office-away');
    Route::get('/organization', Organization::class)->middleware(Ability::ViewOrganization->middleware())->name('organization');

    Route::get('/relievers', Relievers::class)->middleware(Ability::ManageRelievers->middleware())->name('relievers');

    Route::middleware(Ability::ManageDocuments->middleware())->group(function () {
        Route::get('/documents', CompanyDocuments::class)->name('documents');
        Route::get('/documents/{document}/file', ShowCompanyDocumentController::class)->name('documents.file');
    });

    Route::get('/trainings', Trainings::class)->middleware(Ability::ManageTraining->middleware())->name('trainings');

    // The NTC radio masterlist: Admin and Operation HQ.
    Route::get('/radios', Radios::class)->middleware(Ability::ManageRadios->middleware())->name('radios');

    // Equipment: issuers (Operations for guards, Admin for office staff), and heads deciding
    // damage or loss reports (the page checks Equipment::canOpen).
    Route::get('/equipment', Equipment::class)->name('equipment');
    // An item's photo in the shop: any signed-in account.
    Route::get('/equipment/items/{item}/photo', ShowEquipmentPhotoController::class)->name('equipment.photo');

    // Petty cash: Accounting and leadership see every fund; a custodian their own (the page checks PettyCashFund::canOpenPage).
    Route::get('/petty-cash', PettyCash::class)->name('petty-cash');
    Route::get('/petty-cash/expenses/{expense}/receipt', ShowPettyCashReceiptController::class)->name('petty-cash.receipt');

    // Referrals (hiring perks): HR marks them hired or not, and sets the bonus terms.
    Route::get('/referral-cases', ReferralCases::class)->middleware(Ability::ManageReferrals->middleware())->name('referral-cases');

    // Merits and demerits: whoever supervises the person (MeritRecord::canGive); the types: HR.
    Route::get('/merits', Merits::class)->middleware(Ability::GiveMerits->middleware())->name('merits');

    // Office staff's reimbursements: the Executive approves, Accounting sees them all.
    Route::get('/reimbursement-requests', ReimbursementRequests::class)->middleware(Ability::ViewReimbursements->middleware())->name('reimbursement-requests');

    // Deployment costs (roadmap 5): Operations claims what a guard paid; paid on the guard's payslip.
    Route::get('/deployment-claims', DeploymentClaims::class)->middleware(Ability::FileDeploymentClaims->middleware())->name('deployment-claims');

    // Notices to Explain: issued and decided by whoever supervises the person (NoticeToExplain::canIssue).
    Route::get('/notices-to-explain', NoticesToExplain::class)->middleware(Ability::IssueNoticesToExplain->middleware())->name('notices-to-explain');

    // HR, or the Executive for HR staff's own complaints: the page checks either (Complaint::handlesAny).
    Route::get('/complaint-cases', Complaints::class)->name('complaint-cases');
    // Proof on a complaint: the complainant or its handler (the controller checks).
    Route::get('/complaint-files/{file}', ShowComplaintFileController::class)->name('complaint.file');

    // HR, or the Executive for HR staff's own resignations: the page checks either (Resignation::handlesAny).
    Route::get('/resignations', Resignations::class)->name('resignations');

    // Final pay: Accounting prepares and signs, the Executive signs last (only they see the amounts). Cash bonds: Accounting.
    Route::get('/final-pay', FinalPays::class)->middleware(Ability::SignFinalPay->middleware())->name('final-pay');
    Route::get('/cash-bonds', CashBonds::class)->middleware(Ability::PrepareFinalPay->middleware())->name('cash-bonds');

    Route::get('/audit-log', AuditLog::class)->middleware(Ability::ViewSystemLogs->middleware())->name('audit-log');
});
