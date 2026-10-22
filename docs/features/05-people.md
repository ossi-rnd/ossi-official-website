# 5. People: employees, shifts, holidays

## Employee records (`view-people` to open, `manage-people` to edit)

HR (and Super Admin) add and edit records. Admin, Accounting, the President or Vice President, Operation HQ and Satellite Operation **view** them: no Edit button, and no Contact or Medical sections. Government IDs are shown (masked) to HR and Accounting only, and the pay rate card to HR, Accounting and the President or Vice President (`view-pay-rates`). A Satellite Operation sees only guards in its region and can change a guard's client, post and shift (**Change deployment**, see [Clients](17-clients-and-regions.md)).

`employee_profiles` is HR's record and exists **before** the login account (`user_id` nullable). It holds the employee number and email used for registration, assignment (client/detachment, post, position, shift, hire date, status), payroll flags and personal data. The detachment is picked from **Clients** (Region → Province → Client); see [Clients by region](17-clients-and-regions.md).

| Pattern | Where | Why |
| --- | --- | --- |
| Livewire **Form object** | `App\Livewire\Forms\EmployeeProfileForm` | 22 fields and their rules stay out of the page components; Add and Edit share it |
| One Action for create + update | `SaveEmployeeProfile` | Same audit and transaction either way |
| Encrypted casts | `EmployeeProfile::casts()` | Government IDs and medical notes are sensitive personal information |
| Masked display | Employee detail page shows `••••5678` | Full value only inside the edit form |
| Explicit payroll flag | `is_office_staff` | Spec: store it, don't derive it from roles. Night differential isn't a flag: payroll computes it from the hours worked 10 PM–6 AM |
| `$attributes` defaults | Models | A DB default is not on the PHP object after `create()` |

Account status on the list: **Not registered** (no account yet), **Email unconfirmed**, **Active**.

Setting someone to Resigned or Terminated blocks their sign-in and ends their open sessions.

## Shifts (`configure-system`)

- `start_time`, `end_time`, `grace_minutes`, `is_default`. Exactly one default (cleared in the same transaction by `SaveShift`).
- An end before the start means the shift runs overnight (22:00–06:00). `Shift::crossesMidnight()` and `scheduledMinutes()` handle it.
- `Shift::fallback()`: the default shift, or 09:00–18:00 with 10 min grace from `config/attendance.php`.
- A shift with employees on it can't be deleted.

**Change from the old app:** the shift lives on the employee profile, not on `users`, because HR assigns it before the account exists.

## Holidays (`configure-holidays`: Super Admin and Admin)

Date (unique), name, type: regular or special non-working. Uniqueness is checked with `whereDate()`, because SQLite stores the date cast as `Y-m-d 00:00:00` and `Rule::unique` would miss collisions. `HolidaySeeder` adds the fixed-date Philippine holidays; movable ones are entered by hand.

Tests: `tests/Feature/People/EmployeesAndRolesTest.php`.

## Office staff vs. guards

Detachment and post describe where a **guard** is deployed. Office staff (HR, Admin, Accounting…) have neither, so:

- `EmployeeProfile::hasDeployment()` is the one question every screen asks. My Profile and the employee record hide Detachment and Post for office staff. The Attendance Log says "Office staff", and the dashboard says "Office". The Employees list's **Assignment** column shows a guard's detachment · post and an office employee's **position** ("HR Staff", "Accounting Staff"), or "Office staff" until HR picks one (`rosterAssignmentLabel()`; `assignmentLabel()` is unchanged for every other screen).
- In the employee form, **Office staff** is the first Assignment field. Ticking it hides Detachment and Post, and the Position list then offers only `Position::for($isOfficeStaff)`: "Office Staff" in the office, guard ranks in the field.
- Saving an office-staff record clears any detachment or post left over from a guard record and leaves the position empty until HR picks the office job. A position of the wrong kind is dropped instead of failing validation on a field that's hidden.
- Office jobs: **Admin, HR, Accounting, IT, Operations Head, Satellite Operations Officer** (`Position::for(true)`), matching the roles. Security Manager (retired 2026-10-22), President and Vice President (2026-10-21) are no longer offered, in the form, the CSV import (`Position::offered()`) or Daily Rates (shown there only while someone still holds one); older records keep theirs. The **position** is the job and sets the company rate (Daily Rates → Rate per position). The **role** (Roles & Access) sets what they can open. Someone in IT usually also gets the Developer role, someone in HR the HR role, and so on.
- Office staff are also not asked for guard-only 201 documents (`Requirement::isGuardOnly()`).

## The employee form: number, hire type and client site

- **The employee number is generated, not typed.** It's the OSSI ID card's control number, made the same way as the ID card app (`Documents\OSSI_ID_GEN_R_SOFTWARE`): **hire date** as YYMMDD + the **province of birth** code + a dash + **4 random digits** + `-RH` for a rehire, e.g. `260627PAM-0950`, `260627PAM-0950-RH` (`EmployeeNumber::generate`). The 4 digits are picked from those free among numbers with the same date and province, so it never repeats and finds one while any is left. HR fills **Hire date** and **Province of birth** (both required for a new record) and the **Newly hired / Rehired** switch; the box shows `260627PAM-····-RH` until it's saved, then the toast gives the full number to share.
- **Province of birth:** the ISO 3166-2:PH codes of the 82 provinces plus **NCR** for Metro Manila (`App\Support\Provinces`), in a **searchable** list. It isn't a column: the number holds it, and `EmployeeNumber::provinceCode()` reads it back (on edit it's shown, not changed).
- **An ID card already printed:** "Already has an OSSI ID card? Enter the card's number" takes the card's number instead. It must be in the card format with a real date and province code (`EmployeeNumber::isValid`, also used by the staff import and `app:create-super-admin`), and fills in the hire date and province from it; a hire date or province that doesn't match it is refused. "Generate it instead" goes back.
- **Editing keeps the number**, even if the hire date changes: it's on their ID card and they registered with it (as the card app never changes a control number). "Their ID card has a different number?" replaces it with the card's. Older numbers (`EMP-1001`, `703668-DDN-6515`, `240601-SEC-1001`) stay as they are and still register.
- **Client site: Region → Province → Detachment.** Under a "Client site · where they're deployed, not their home address" heading, Region (only regions with clients) narrows Province (only that region's provinces with clients), which narrows Detachment. Choosing a detachment first fills in its region and province; changing the region clears the province; narrowing drops a detachment outside the area, so a hidden choice can't be saved. Nothing new is stored: region and province are the client's (`EmployeeProfileForm::areaChanged()`, `regionsWithClients()`, `provincesInRegion()`, `clientsInArea()`, called from each page's `updatedForm` hook). The person's own address stays under Contact.
- Tables label the columns **Name** and **Employee No.** (the CSV exports too).

Tests: `tests/Feature/People/EmployeeAreaPickerTest.php`.

## Lists by client site: Employees and Attendance Log

A **Client site** bar under the list header: Region → Province → Client, plus **Office staff (no client)** and **Clear**. Each pick clears the ones after it (`updatedAreaRegion` / `updatedAreaProvince`) and goes back to page 1. It filters by the client a person is deployed to (`employee_profiles.client_id` → the client's region and province), never their home address; the Attendance Log uses the person's own client, so a reliever's punches at another site stay under their home detachment. The choices live in the URL (`?region=r4a&province=Cavite&client=3`).

Only regions and provinces that have clients are offered, and a Satellite Operation is offered its own region only (it sees no one else anyway: `visibleTo`). *Office staff (no client)* is offered only to those with `view-office-staff`: Operation HQ sees guards only, on every page (`visibleTo` and `canSeeProfile` hide office staff from it).

**Employees: one search, All · Guards · Office staff.** One search box finds anyone by name, email or employee number. Tabs under the header narrow the list to guards or office staff, each with a count that follows the search and filters; they combine with status, account and client site, and the tab is kept in the URL (`?kind=office`). The tabs are for those with `view-office-staff` (HR, the President or Vice President, Admin, Accounting, Super Admin). **Operations** sees guards only, with no tabs, as on the dashboard; `?kind=office` changes nothing for them. Tests: `EmployeesAndRolesTest.php`.

One trait for both pages, `App\Livewire\Concerns\FiltersByArea` (`filterProfilesByArea()` on an `EmployeeProfile` query), and one view, `livewire/admin/partials/area-filter.blade.php`. Tests: `tests/Feature/People/AreaFilterTest.php`.

## Organization chart (the President or Vice President)

**Overview → Organization** (`view-organization`: President, Vice President and Super Admin) shows the company the way headquarters draws it, filled from live data:

```
Board of Directors
  → President
    → Vice President
      → Super Admin → HR · Accounting · Admin · Operation HQ
                                                  └→ Satellite Operation → Regional → Provincial → clients → guards
```

- **Headquarters** (top card): each box lists who holds it (up to three names linking to their records, "+N more" for the rest), or "Not assigned". Everyone is placed by **role** (Roles & Access), the **President** and **Vice President** included: since 2026-10-21 they, like the Super Admin, are accounts **without an employee record** (no employee number, not on payroll), so they're roles, not positions. The **Board of Directors** box at the top (a hexagon) lists the **President** (a director by law, Revised Corporation Code Sec. 24) and the **Vice President** (company decision), both by role. There is no tick on employee records: the "Member of the Board of Directors" checkbox was removed on 2026-10-21 (nobody knew when to tick it). If other directors need showing, the plan is a separate "Board members" list.
- **Three different things.** The **Board** governs and elects the officers (the President and VP automatically, employees by a tick). The **President** and **Vice President** are roles on their own accounts, with the same access (Roles & Access). An employee's **position** is their job and sets their rate; President and Vice President are no longer offered as positions (`Position::for`), and older records that have one still read. History: a single "Executive" role was renamed on 2026-10-20 and was a position before 2026-10-19.
- **Field operations** (bottom card): one row per region that has clients, with its **Satellite Operation officer(s)** (`operations_region`; amber "nobody assigned" when none), and counts of provinces, clients and active guards. Open a region for its provinces and their clients; a client's guard count opens the list of its guards (post, rank, hours).
- Read-only: people and clients are changed on Employees, Roles & Access and Clients.
- The chart is drawn with HTML boxes and CSS connectors (`.org-chart`, `.org-row`, `.org-cell`, `.org-line`, `.org-hexagon` in `app.css`). On a phone it scrolls sideways and opens centred on the top.

Code: `App\Queries\People\OrganizationQuery` (a few queries whatever the size), `App\Livewire\Admin\Organization`, `livewire/admin/organization.blade.php` and `partials/org-box.blade.php`. Tests: `tests/Feature/People/OrganizationTest.php`.

## Pay rate (set by Accounting, seen by HR)

The employee record has a read-only **Pay rate** card: the daily rate paid today, where it comes from (personal rate, their position's company rate, or the company default), and the history of personal rates (who set it, note). **Accounting** sets rates, because payroll is theirs (`SetEmployeeRate` requires `manage-payroll`). HR sees a locked **Set by Accounting** button with a tooltip. Employees see their rate on their payslip ("13 days × ₱755.00").

**New employees.** *Add employee* has no pay field. Once the record is saved, if no company rate exists for their position, everyone who manages payroll gets a bell notice, *Daily rate needed* (`DailyRateNeeded`), linking to Daily Rates. Until a rate is set, payroll uses the company default and flags anyone below the minimum wage.

## Government ID formats

The four government numbers take **digits only**, in each agency's pattern (`App\Support\GovernmentId::FORMATS`, one list for the rules, the placeholders and the dashes):

| Field | Digits | Pattern |
|---|---|---|
| SSS No. | 10 | 00-0000000-0 |
| PhilHealth No. (PIN) | 12 | 00-000000000-0 |
| Pag-IBIG No. (MID) | 12 | 0000-0000-0000 |
| TIN | 9, or 12 / 14 with a branch code | 000-000-000(-000 / -00000) |

- While HR types, `formatGovernmentId` (`resources/js/government-id.js`) drops non-digits and adds the dashes; `inputmode="numeric"` opens the number keypad.
- On save, `GovernmentId::format()` tidies spaces and dashes, and a closure rule refuses letters or a wrong length ("SSS No. has 10 digits (00-0000000-0); this one has 9."). Blank is allowed: a new hire may not have every number yet.
- Other IDs (below) have no fixed format, so they aren't checked.

## Clean input: phones, names, employee number

`App\Support\PhilippineFormats` checks what people type, and the fields drop the wrong characters as they're typed:

- **Contact number** (the employee's own): a Philippine mobile, saved as `0917 123 4567` from `+63 917-123-4567`, `639171234567`, etc. It must be a mobile because load requests use it.
- **Emergency contact phone**, and the **client's contact number**: a mobile, or a landline with its area code (`(02) 8123 4567`, `046 123 4567`, or a 7-8 digit local number). A mobile with a digit missing starts with 09, so it can't pass as a landline.
- **Names** (full name, emergency contact, client contact person, Account Settings): letters (ñ, é…) and `. , ' -` only, with spaces tidied. Example: "Ma. Cristina Dela Cruz-Reyes Jr.".
- **Employee number**: on the ID-card field, `formatEmployeeNumber` shapes it as typed (260627PAM-0950-RH). On Register it is only capitalised, so older numbers (EMP-1001) still work, and `EmployeeNumber::normalize` puts back dashes left out of a current-format number.

## Other IDs

Besides SSS, PhilHealth, Pag-IBIG and TIN, HR can add **other IDs** on the employee form: rows of an ID name and its number (**Add another ID**, a remove button per row, up to `EmployeeProfile::MAX_OTHER_IDS` = 10). The name field suggests common ones (Security Guard License (LESP), Driver's License, Passport, UMID, PhilSys National ID, PRC License, Postal ID, Voter's ID, NBI / Police / Barangay Clearance) but any name can be typed. A name needs its number and a number its name; blank rows are dropped on save.

- Stored in `employee_profiles.other_ids` as a list of `{label, number}`, **encrypted at rest** (`encrypted:array`) like the four government IDs, and in `EmployeeProfile::SENSITIVE` so the audit log shows "(changed)", never the numbers.
- The employee page shows them **masked** in Government IDs (to HR and payroll, as the other IDs); the employee sees them in full on My Profile.
- Privacy notice: a line for other identification, with its basis (a guard's license: required by RA 11917; anything else: optional, under the company's legitimate interest, Sec. 12(f), never "consent", which the notice deliberately doesn't rely on).
