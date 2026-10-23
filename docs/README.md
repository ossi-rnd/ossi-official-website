# OSSI Attendance & Payroll: developer guide

A rebuild of `OSSI_Attendance_Payroll_System` following `MVP_REBUILD_SPEC.md`: same screens and look, cleaner architecture, Fortify authentication, and registration restricted to employees HR has added.

Stack: Laravel 13, Livewire 4 (class components), Alpine, Tailwind 4, Fortify, Pest 5, Larastan level 8, Pint.

## Start here

```bash
composer install && npm install
php artisan migrate:fresh --seed   # demo data, every password is "password"
npm run build
herd secure ossi_official_site      # camera + GPS need HTTPS
composer dev                        # server + queue worker (sends mail) + Vite
composer test                       # Pint, PHPStan, Pest: same as CI
```

Open https://ossi_official_site.test.

| Demo account | Role | Lands on |
| --- | --- | --- |
| superadmin@example.test | Super Admin | Dashboard |
| admin@example.test | Admin | Dashboard |
| hr@example.test | HR | My Attendance |
| accounting@example.test | Accounting | My Attendance |
| developer@example.test | Developer | My Attendance |
| president@example.test | President (no employee record) | Dashboard |
| vp@example.test | Vice President (no employee record) | Dashboard |
| opshq@example.test | Operation HQ (Operations Head) | Dashboard |
| satellite@example.test | Satellite Operation (Satellite Operations Officer); demo region Region IV-A, any region can be set on Roles & Access | Dashboard |
| employee@example.test | Employee (Maria Santos) | My Attendance |

To try registration: `/register` with **250115-SEC-3001** and **juan.delacruz@example.test**. Any other combination is refused and shows up in the Audit Log.

## Where things live

| Folder | What goes there | Rule |
| --- | --- | --- |
| `app/Actions` | One class per write (approve, generate, punch…) | `final`, one public `handle()`, wraps change + audit entry in one transaction |
| `app/Queries` | Read models for screens | `final`, `handle()`, bulk queries, no N+1 |
| `app/Services` | Pure calculators and infrastructure | Calculators do no queries (arch test enforces) |
| `app/Data` | Readonly value objects passed between layers | `final readonly` |
| `app/Enums` | Roles, gates (`Ability`), statuses | Backed by strings in `string(20)` columns |
| `app/Livewire` | Thin page components | Validate → authorize → call an Action → notify |
| `app/Http` | Middleware and invokable controllers | Controllers only for non-Livewire endpoints |
| `resources/views/components/ui` | Design system (button, input, card, modal…) | Tailwind classes live in Blade |

A request, end to end: **Route** (`can:` gate) → **Livewire component** (re-checks the gate, validates) → **Action** (business rules, DB transaction, audit) → **Model**. Reads go Component → **Query** → **pure calculator**.

## Feature guides

1. [Authentication with Fortify and HR-only registration](features/01-authentication.md)
2. [Roles and gates (nine roles, regional scope, who approves what)](features/02-roles-and-gates.md)
3. [Privacy notice, idle timeout and session security](features/03-security.md)
4. [Audit log](features/04-audit-log.md)
5. [People: employees, shifts, holidays](features/05-people.md)
6. [Attendance: clock in/out with selfie and GPS](features/06-attendance.md)
7. [Leave and overtime requests](features/07-requests.md)
8. [Payroll](features/08-payroll.md)
9. [Dashboard and self-service](features/09-dashboard-and-self-service.md)
10. [Testing and quality gates](features/10-testing.md)
11. [Deploying to Laravel Cloud](features/11-deployment.md)
12. [Version 2 roadmap: the wishlist checked against the code](features/12-version-2-roadmap.md)
13. [V2 phase 2.0: notifications, probation alerts, announcements, FAQs, late details, screenshot deterrents](features/13-v2-quick-wins.md)
14. [V2 phase 2.1: the 201 file (checklist, uploads, expiry alerts, RAE)](features/14-201-file.md)
15. [Borrow Load: mobile load on credit, deducted on payroll](features/15-load-purchase.md)
16. [13th month pay (PD 851): runs, balance runs, employee view](features/16-thirteenth-month.md)
17. [Clients by region (Region → Province → Client) and Change deployment](features/17-clients-and-regions.md)
18. [Report Absence and relievers](features/18-relievers.md)
19. [Documents and licenses (renewals, due-date alerts)](features/19-documents-and-licenses.md)
20. [Guards' training](features/20-training.md)
21. [Complaints (confidential, HR)](features/21-complaints.md)
22. [Company Policy](features/22-company-policy.md)
23. [Duty Roster, dynamic attendance and anti-cheating (geofence, GPS accuracy, manual records)](features/23-duty-roster.md)
24. [Patrol checkpoints (QR rounds) and time away from the post](features/24-patrols-and-away.md)
25. [UI conventions: loading buttons, status marks, the bell badge, dialogs](features/25-ui-conventions.md)
26. [Resignation and clearance (Labor Code Art. 300)](features/26-resignation.md)
27. [Final pay and the cash bond (Accounting signs, the President or Vice President releases)](features/27-final-pay-and-cash-bond.md)
28. [Merits and demerits](features/28-merits-and-demerits.md)
29. [Notices to Explain (the twin-notice rule)](features/29-notices-to-explain.md)
30. [Promotions and position history](features/30-promotions.md)
31. [Reimbursements (office staff only)](features/31-reimbursements.md)
32. [Birthdays, petty cash, NTC radios and referrals](features/32-birthdays-petty-cash-radios-referrals.md)
33. [Notifications: who is told what](features/33-notifications.md)
34. [Equipment: uniforms, radios and gear (authority to deduct, accountability, damage and loss)](features/34-equipment.md)

What changed, day by day, and why: [CHANGELOG.md](CHANGELOG.md).

## Deliberate changes from the old system

| Old | New | Why |
| --- | --- | --- |
| Custom Livewire login, OTP, account requests approved by HR | Fortify login, email verification, optional 2FA; registration only claims an HR record | Battle-tested auth; HR approves people *before* they register, not after |
| spatie/laravel-permission + Gate checks of role names | `Role` and `Ability` enums, `user_roles` table | Type-safe, one file defines who can open what |
| spatie/laravel-activitylog | Own append-only `activity_log` | Records exactly the events the spec names, inside the same transaction |
| Payslip figures editable before "Generate" | Figures always come from the calculator | Every peso is traceable; manual adjustments would be a separate, audited feature |
| Two copies of payroll input gathering | One `ResolvePayslips` used by both screens | Identical numbers by construction |
| Office/night status guessed from roles and recent logs | `is_office_staff` / `is_night_shift` flags on the profile | Spec requirement; explicit beats inferred |
| Photos on local disk | Configurable private disk (`ATTENDANCE_PHOTO_DISK`) | Laravel Cloud's disk is wiped on deploy |
| Government IDs in plain text | Encrypted casts | A leaked database dump doesn't leak SSS/TIN numbers |
| Shift on `users` | Shift on `employee_profiles` | HR assigns it before the account exists |
| Phase-2 modules (complaints, reimbursements, 201 files, equipment, resignations, weather) | 201 files, complaints, relievers, documents/licenses, training, company policy, resignations and reimbursements built since; equipment and weather not built | Built as the owner asked for them |
