# 2. Roles and gates

## The model

- `App\Enums\Role`: nine roles, stored as strings in `user_roles` (a user can hold several).
- `App\Enums\Ability`: the gates. `roles()` says who passes each one.
- `AppServiceProvider::defineGates()` loops over `Ability::cases()` and calls `Gate::define()` for each. The rules live in **one** `match`.
- **Viewing and changing are separate gates** wherever a role may look but not touch. For example, the President or Vice President monitors everything, but `manage-people` still decides who can edit a record.

## The roles

| Role | What they do |
| --- | --- |
| **Super Admin** | Everything: system settings, holidays, clients, roles, audit log. |
| **President / Vice President** | Monitors (views) the dashboard, attendance, employees, leave and overtime **without editing**. Posts announcements. **Approves office staff's leave and overtime** (HR's included) and **handles complaints filed by HR staff**, and monitors Office Away. **Paid outside this payroll** (`Role::paidOutsidePayroll()`): even with an HR record, no payslip, no DTR row, no daily rate needed, and none of My Attendance, My Leave, My Overtime, My Payslips, Borrow Load, My Complaints (hidden, and `EnsureOnPayroll` refuses the URLs with 403). |
| **Admin** | Company documents, licenses, client and legal documents, guards' training, holidays, the employee list (view). Also: guards' leave, overtime, Borrow Load. |
| **HR** | Onboarding and employee records, **guards' leave**, overtime, complaints. |
| **Accounting** | Payroll, daily rates, 13th month pay, **Borrow Load approval**, employee list (view). |
| **Operation HQ** | Head of operations: monitors leave, **approves overtime**, assigns relievers. |
| **Satellite Operation** | Same as Operation HQ, but **for one region only**, and can also change a guard's client, post and shift (Change deployment). |
| **Developer** | Reads the audit log. No HR or payroll access. |
| **Employee** | Self-service: attendance, leave, overtime, payslips, Borrow Load, Report Absence, complaints, company policy. |

## The gates

| Gate | Roles | Pages / actions |
| --- | --- | --- |
| access-employer-area | Admin, Super Admin, President, Vice President, Operation HQ, Satellite | Dashboard |
| supervise-attendance | Admin, HR, Super Admin, President, Vice President, Operation HQ, Satellite | Attendance Log |
| monitor-requests | Admin, HR, Super Admin, President, Vice President, Operation HQ, Satellite | Leave Requests and Overtime Requests (the lists) |
| review-field-leave | HR, Super Admin | Deciding a **guard's** leave (final, after Operations endorses it) |
| endorse-guard-leave | Satellite Operation (its region), Operation HQ | Endorsing a guard's leave up to HR |
| review-office-leave | President, Vice President, Super Admin | Deciding HR staff's leave (stage "executive"). Other office staff's leave is decided by their head (`Heads`) |
| review-overtime | HR, Admin, Super Admin, Operation HQ, Satellite | Deciding a **guard's** overtime |
| review-office-overtime | President, Vice President, Super Admin | Monitoring office staff's overtime. Deciding it follows the head (`Heads`): the President or VP for HR staff's |
| view-people | HR, Super Admin, Admin, Accounting, President, Vice President, Operation HQ, Satellite | Employees and Employee Profile (read-only) |
| manage-people | HR, Super Admin | Add and edit employee records, 201 files |
| view-pay-rates | HR, Accounting, Super Admin, President, Vice President | The pay rate card on an employee record |
| deploy-guards | HR, Super Admin, Satellite | Change deployment (client, post, shift) |
| manage-payroll | Accounting, Super Admin | Payroll, Daily Rates, 13th Month, Late Deduction, Tax |
| configure-system | Super Admin | Shifts, Roles & Access |
| manage-offices | Admin, Super Admin | OSSI Offices (head office, branches): where office staff clock in |
| manage-clients | Operation HQ, Admin, Super Admin | Clients (sites, location, geofence): set up before HR onboards guards there. Not Satellite Operation (below HQ) |
| manage-guard-phones | Operation HQ, HR, Super Admin, Satellite (its region) | A guard's company phone: alerts, make a phone theirs, forget it |
| configure-holidays | Super Admin, Admin | Holidays |
| view-system-logs | Developer, Super Admin | Audit Log |
| publish-content | Admin, HR, Super Admin, President, Vice President | Announcements, FAQs |
| manage-load-requests | Admin, Accounting, Super Admin | Load Requests |
| approve-payroll-adjustments | Super Admin | Second approval on large adjustments |
| manage-relievers | Operation HQ, Satellite, Super Admin | Relievers |
| manage-roster | Operation HQ, Satellite (its region), Super Admin | Duty Roster, manual attendance |
| manage-patrols | Operation HQ, Satellite (its region), Super Admin | Patrols: checkpoints, rounds, guards away from post |
| monitor-office-away | President, Vice President, HR, Operation HQ, Super Admin | Office Away: office staff out of the office, their location. The President or VP see everyone; HR and Operation HQ see the people they head. The head approves (`Heads`) |
| view-organization | President, Vice President, Super Admin | Organization: the headquarters chart and the field side (region → province → client → guards), read-only |
| manage-documents | Admin, Super Admin | Documents (company, licenses, client & legal) |
| manage-training | Admin, Super Admin | Training |
| handle-complaints | HR, Super Admin | Complaints (confidential), except those filed by HR staff |
| view-office-staff | Super Admin, President, Vice President, Admin, HR, Accounting | Office staff in the dashboard's numbers, charts and DTR. Without it (Operation HQ, Satellite Operation) the dashboard counts every guard in every region and no office staff (`User::scopeOnDashboardOf`); a Satellite still opens records in its region only |
| handle-resignations | HR, Super Admin | Resignations: accept the last day, clearance, record a paper letter ([26](26-resignation.md)) |
| handle-hr-resignations | President, Vice President, Super Admin | An HR member's own resignation (`resignations.for_executive`), which HR never sees |
| prepare-final-pay | Accounting, Super Admin | Final Pay: prepare, adjust, send for signatures; Cash Bonds ([27](27-final-pay-and-cash-bond.md)) |
| sign-final-pay | Accounting, President, Vice President, Super Admin | Final Pay: open and sign; the President or Vice President signs last and releases it. Only these see final pay amounts |
| give-merits | Operation HQ, Satellite Operation, HR, President, Vice President, Super Admin | Merits & Demerits. Per person (`MeritRecord::canGive`, the head): guards by Operations (a Satellite in its region) or HR, a Satellite by Operation HQ, other office staff by HR, HR staff by the President or Vice President ([28](28-merits-and-demerits.md)) |
| approve-reimbursements | President, Vice President, Super Admin | Reimbursements: approve or reject office staff's requests, never their own ([31](31-reimbursements.md)) |
| file-deployment-claims | Operation HQ, Satellite Operation, Super Admin | Deployment Costs: file what a guard paid for a deployment (a Satellite: its region's guards; `Reimbursement::canFileFor`), paid on the guard's payslip once the President or Vice President approves ([31](31-reimbursements.md#deployment-costs-roadmap-5)) |
| view-reimbursements | President, Vice President, Accounting, Super Admin | The Reimbursements page and every receipt; Accounting sees them but doesn't decide |
| manage-merit-types | HR, Super Admin | The list of merit and demerit types and their points |
| issue-notices-to-explain | Operation HQ, Satellite Operation, HR, President, Vice President, Super Admin | Notices to Explain. Per person (`NoticeToExplain::canIssue`, the head): guards by Operations (a Satellite in its region), a Satellite by Operation HQ, other office staff by HR, HR staff by the President or Vice President; HR and the President or Vice President read them all ([29](29-notices-to-explain.md)) |
| handle-hr-complaints | President, Vice President, Super Admin | Complaints filed by HR staff (`complaints.for_executive`), which HR never sees |
| manage-policies | Admin, HR, Super Admin | Editing the Company Policy page |

Roles & Access shows this same table, built from the enum.

**One role per person** (2026-10-21). The Change roles window picks **one** job role (radio buttons), or "No extra role" for a regular employee. "Employee" isn't offered: anyone with an employee record keeps it automatically (it gates nothing; the personal pages come from the employee record), and accounts without one (Super Admin, President, Vice President) never get it. An older account holding two job roles shows a warning, and saving keeps the one picked. Only the picker changed: what each role can open (`Ability::roles()`) is untouched, `SyncUserRoles` and its guard rails (last Super Admin, no Super Admin/President/VP on an employee record, region for Satellite) are the same, and `ImportStaff` still assigns roles as before.

**Before they register** (2026-10-22). Roles belong to accounts, and someone HR just added has no account until they register. Roles & Access lists them under **Not registered yet** (office staff first) with **Set role**. The role waits on the record (`employee_profiles.pending_role`, plus `pending_region` for a Satellite; `SetPendingRole`, audited), and `ClaimEmployeeAccount` gives it to the new account on registering, then clears it. The rules are the same as `SyncUserRoles`: a region for a Satellite, and never Super Admin, President or VP on a record.

## Who decides leave (`ReviewLeaveRequest::requiredAbility()`)

- A **guard's** leave goes up a chain, and **HR has the final say** (`review-field-leave`: HR, with the Super Admin as backup; no longer Admin):
  1. **Satellite Operation** of the guard's region endorses it to Operation HQ (`endorse-guard-leave`);
  2. **Operation HQ** endorses it to HR. Operation HQ may also endorse at step 1 when the Satellite hasn't (recorded as "Satellite step skipped"), so leave is never stuck;
  3. **HR** approves or rejects. HR can't decide before Operation HQ has endorsed.

  No Satellite account for the region (or a guard not deployed yet): it starts at step 2. Endorsers choose **Endorse** or **Not recommended** (a reason is required); either way it moves up, because only HR decides. Each step gets a bell and email (`LeaveAwaitingYou`) with the endorsements so far, and HR's review window shows them. `leave_requests.stage` says where a request is waiting ("With Operation HQ" on the list and on the guard's My Leave), `leave_endorsements` keeps each endorsement, and the routing lives in `App\Support\LeaveRouting`. The Leave Requests list has a **Waiting for me** filter, and the sidebar badge counts what's at your step.
- **Office staff's** leave and overtime go to **their head**, who decides (`App\Support\Heads`, see "The chain of heads" below). A user with no HR profile counts as office staff.
- Monitoring roles see the requests they're allowed to, and in place of the Review button they see who decides: "Their head decides it: HR." `ReviewLeaveRequest::blockedReason()` / `ReviewOvertimeRequest::blockedReason()` produce that text, and the Actions enforce the same rule.
- Overtime: a guard's needs `review-overtime` (Operations, HR, Admin; the President or VP only monitors it); an office staffer's, their head. When office staff file overtime, their head is told automatically (`RequestFiled`).
- **Who sees office staff's requests** (`RoutedByStaffKind` on both models): their head, plus the President or VP and the Super Admin, who monitor them all. Lists get **Office staff / Guards** tabs for everyone who sees both. The President or VP's office tab says "HR staff's are yours to decide". The sidebar badges count what's waiting for **your** decision.

## The chain of heads (`App\Support\Heads`, the owner's rule, 2026-10-23)

| Person | Their head |
|---|---|
| A guard | Operations: Operation HQ, and the Satellite of their client's region |
| HR | the President or Vice President |
| A Satellite Operation | Operation HQ |
| Other office staff (Admin, Accounting, Operation HQ, Developer, staff with no role) | HR |

One class answers both "who heads this person" (`Heads::of`, `Heads::label`) and "does X head Y" (`Heads::isHeadOf`). It's used for:
- attendance alerts (missed clock-out, office staff not clocked in);
- deciding office staff's leave and overtime;
- watching and approving their time away (Office Away; `monitor-office-away` now also HR and Operation HQ, each seeing only the people they head);
- Notices to Explain and merits.

The Super Admin may act for any head, as backup. The President or VP still **see** every office staffer's requests and time away, and decide only HR staff's. A Satellite's leave waits at stage `hq`, where Operation HQ **decides** it (not endorses it, as for a guard). For Operation HQ, the "guards only" visibility rule includes the Satellites it heads.

## Regional scope (Satellite Operation)

- Super Admin sets the region on **Roles & Access** when picking Satellite Operation. It can be any of the 18 regions, and each Satellite account has its own (one account per region, or as many as needed). The demo account `satellite@example.test` is set to Region IV-A only as an example. A region is required, and it's cleared when the role is removed (`users.operations_region`).
- `Role::seesWholeCompany()` is false only for Satellite Operation, Developer and Employee. `User::isRegionallyLimited()` is true when none of a user's roles sees the whole company, so a Satellite who is also HR sees everyone.
- `User::scopeVisibleTo($viewer)` and `EmployeeProfile::scopeVisibleTo($viewer)` limit lists to guards deployed at clients in that region. They're applied on the Employees, Leave, Overtime, Attendance Log, Dashboard and Relievers pages, and to the sidebar badges.
- Single records go through `User::canSeeProfile()` and `canSeeEmployee()`: the employee page returns 403, and reviews and deployments outside the region are refused in the Action.
- A Satellite account with no region set sees nobody.

## Checked twice: on the route and inside every write

```php
// routes/web.php
Route::get('/payroll', Payroll::class)->middleware(Ability::ManagePayroll->middleware()); // "can:manage-payroll"

// app/Livewire/Admin/Payroll.php
public function generate(int $userId, GeneratePayslip $generatePayslip): void
{
    $this->authorize(Ability::ManagePayroll->value); // again, on every write
    ...
}
```

Why twice? The spec lists a bug from the old app: a page opened while you *had* the role kept its buttons working after the role was removed. Livewire does re-run `can:` route middleware on its background requests (it's on Livewire's "persistent middleware" list), but that protection lives in `routes/web.php`. If the component were ever mounted elsewhere, the in-method check still holds. The test `blocks the next write once a role is removed` proves it.

`verified` is **not** on Livewire's persistent list by default, so `AppServiceProvider` adds it with `Livewire::addPersistentMiddleware()`.

## Why enums instead of spatie/laravel-permission

The old app used spatie, but its permission rows were documentation only: nothing called `$user->can('approve leave')`. Enums make the real rules explicit, need no package, and PHPStan can check them.

## Guard rails when changing roles (`SyncUserRoles`)

- You can't remove your own Super Admin role.
- The last Super Admin can't be removed (row lock + count).
- Satellite Operation needs a region.
- Every change is audited with the old and new roles (and region).

## Navigation from the same source

`App\Support\Navigation::for($user)` builds the sidebar from `Ability` checks, so the menu can never show a link the route would refuse. Adding a page is one line there. **Report Absence** only shows for guards deployed to a post.

## Tests

- `tests/Feature/Access/RouteAccessTest.php` builds its dataset from the `Ability` enum: every role × every staff page, 200 or 403.
- `tests/Feature/Access/OperationsRolesTest.php` covers who approves which leave, Operation HQ and overtime, Satellite's region limits and deployment, the region on Roles & Access, and read-only viewers.
