# 9. Dashboard and self-service

## Dashboard (`access-employer-area`: every staff role but Developer)

One page; each section has its own Ability, never a role name, so each role sees its part of the job and a person with two roles sees both. HR and Accounting land here after login like the other staff; guards and Developers go to My Attendance.

| Section | Who | What |
|---|---|---|
| Waiting for you | everyone here | The sidebar items that carry a count, as cards (`Navigation::for()`): leave and overtime to decide, relievers, complaints, load requests, daily rates, documents due… One source, so the card and the badge can't disagree. "Nothing is waiting for you" when empty. |
| At a glance | per Ability (`DashboardHighlightsQuery`) | `view-organization`: employees (everyone employed; the hint "31 clock in · 4 no account yet" explains why it's more than the Headcount), clients. `manage-people`: probation ending, hired this month, no account yet. `manage-payroll`: the latest run's status, period and pay day. `manage-relievers`: today's absences reported ahead, no-shows, and relievers sent ("2 of 3 posts covered"), from one query. `manage-training`: training in the next 14 days. Each tile opens the page behind it. |
| Today | `supervise-attendance` | Counts (each with its share, "16% of 31"; clicking one filters the DTR to it, again shows everyone), this week's chart, today's chart and the DTR. The Client site bar (`FiltersByArea`, `rowInArea()`) narrows the counts, today's chart and the DTR; the weekly chart stays "everyone you see". |
| Payroll | `manage-payroll` | Not the Payroll page's charts again (those stay there): a summary and what's left to do. One strip: net pay of the latest run with payslips, its deductions, the change from the run before (neutral: more pay isn't good or bad). Then **Runs to finish**: each open run (oldest first, up to 3; else the last paid one) with a four-step tracker draft → processing → approved → paid, its pay day ("in 5 days" / "10 days overdue" with a red dot), and payslips generated / still to generate (`AdvancePayrollRun::employeesWithoutPayslip`, the same count that blocks approval); each opens the run. Totals come from `PayrollOverviewQuery`, as on the Payroll page. "At a glance" adds **Next pay day** ("Oct 20 · in 5 days"; amber within 3 days, red on the day or overdue while unpaid). |
| People by area | `view-people` | Region → province → client (`<details>`; a single region opens by itself), one line per row with what's inside it ("5 provinces · 8 clients"), headcount, and for `supervise-attendance` also in / late / not in yet / on leave and a "% in" column (a small bar plus the number: in ÷ people not on leave, "—" when nobody is expected), so regions compare at a glance. Office staff and "Not deployed yet" rows, and a total. The people icon after a name (on hover; always shown on touch screens) opens Employees filtered to it; a client's name is itself the link. |

- **Who is counted:** `User::scopeOnDashboardOf()`, used by the roster and the weekly chart alike. Without `view-office-staff` (Operation HQ **and** Satellite Operation): every guard in every region, no office staff ("Headcount · all guards"); the Client site bar offers every region and no "Office staff". Everyone else: whom they may see (`visibleTo`), "whole company".
- **A Satellite's records stay regional:** the dashboard shows it every guard, but names in the DTR and the area rows' Employees links are links only for its own region (`opensRegion()`); elsewhere they're plain text, since the record would refuse them. Its other pages (Employees, Attendance Log, Relievers, Roster…) are unchanged.
- `TodayRosterQuery` (three queries) now carries each row's office / region / province / client, so `WorkforceByAreaQuery` counts the areas in PHP with no further query. `#[Computed]` memoizes the roster for the request: counts, charts, areas and table share one fetch.
- **Attendance chart**, laid out like a finance chart: the headline first (today's "% in", big, then the change from yesterday in points, green ↑ / red ↓, and "17 of 30 expected"), **7D / 14D / 30D** tabs beside it (`setTrendDays`, `?range=`; anything else falls back to 7), then one smooth line of the daily rate (`cubicInterpolationMode: 'monotone'`, so it never bulges past a real value) over a soft gradient, with a dashed hover line and a label ("Thu, Oct 15 · 57% in · 17 of 30 expected"). Rate = in ÷ expected, expected = people not on leave; none for days to come. Data: `WeeklyTrendQuery` (a start and a number of days; four queries whatever the headcount or range). The Today ring beside it says "17 in · of 30 expected", the same measure.
- Today's chart is keyed by the chosen area: it is drawn once, so a new area mounts a new one.

Tests: `tests/Feature/DashboardTest.php`.

`DayStatusResolver` decides a day's label with one precedence used everywhere: real log → not yet hired → upcoming → holiday → on leave → pending (today) → absent.

## Self-service pages (every signed-in user)

| Page | Component | Notes |
| --- | --- | --- |
| My Attendance | `Attendance\ClockPanel` + `History` + `PunchLog` | See [Attendance](06-attendance.md) |
| My Leave | `Leave\MyLeave` | File and cancel |
| My Overtime | `Overtime\MyOvertime` | `?date=` prefilled from the clock card |
| My Payslips | `Payroll\MyPayslips` | Same `ResolvePayslips` and `<x-payslip-breakdown>` as payroll |
| My Profile | `Profile\ShowProfile` | Read-only: HR owns these fields |
| Account Settings | Blade + Fortify forms, `Profile\AvatarForm` | Name/email, password, 2FA, photo |

## UI system

- Layout: `components/layouts/app.blade.php` (sidebar, top bar, dark mode, idle timeout). Livewire pages use it through `layouts/app.blade.php`; `#[Title('...')]` sets the heading.
- Components in `resources/views/components/ui`: `button`, `input`, `textarea`, `select` (styled list over a hidden real `<select>`, so `wire:model` keeps working), `checkbox`, `badge`, `status-badge`, `card`, `modal`, `alert`, `stat`, `avatar`; plus `icon`, `money`, `toast`, `payslip-breakdown`.
- Enums return tone names (`Role::tone()`), and the Tailwind classes stay in Blade, because Tailwind only scans the views for class names.
- A few repeated table/field styles are `@layer components` classes in `resources/css/app.css` (`ui-card`, `ui-th`, `ui-td`, `ui-field`).
- Charts: Chart.js wrapped as Alpine components in `resources/js/charts.js`, destroyed with their element so `wire:navigate` doesn't leak canvases.
- Toasts: any component does `$this->dispatch('notify', message: '...')`.

## Locked buttons explain themselves

Rule: never ship a bare `:disabled`. Give `<x-ui.button>` a reason instead:

```blade
<x-ui.button wire:click="advanceRun" tooltip-align="end"
    :disabled-reason="$missing > 0 ? \"{$missing} employees have no payslip yet. Click Generate all first.\" : null">
    Approve run
</x-ui.button>
```

- With a reason, the button is disabled (`disabled aria-disabled="true"`), its `wire:click`/`wire:confirm` are **removed**, and `<x-ui.tooltip>` shows the reason on hover, keyboard focus and tap.
- The tooltip sits on a wrapper, because disabled buttons fire no mouse events. The wrapper has `tabindex="0"` and `aria-describedby`, so keyboard and screen-reader users get the explanation too.
- `tooltip-align="end"` for buttons at the right edge, so the tooltip stays on screen.
- For buttons Alpine locks and unlocks, wrap them in `<x-ui.tooltip when="…">`. The camera's **Capture** says whether it is waiting for the camera or the GPS fix (`captureBlockedReason` in `clock-capture.js`).

## Delete, Remove and Cancel in table rows

Use `<x-ui.row-delete>`, never a red text link:

```blade
<x-ui.row-delete wire:click="delete({{ $client->id }})" wire:confirm="Delete {{ $client->name }}?"
    :disabled-reason="$client->employee_profiles_count > 0 ? '3 guards deployed here. Move them to another client first.' : null">Delete</x-ui.row-delete>
```

- It's **grey until hovered or focused**, then red. A column of red links pulled the eye away from the rows themselves; the red is kept for the moment you aim at it. Edit stays blue.
- It sits a little further from Edit (`ml-4`), so a slip on a phone doesn't land on it. `wire:confirm` still asks first.
- With `disabled-reason`, it's locked with that tooltip instead of letting the click fail after the confirmation. Clients and Shifts use it while people are assigned; Training uses it once someone completed the session. The Action still refuses, so the rule holds even if the button is bypassed.
- Used on Clients, Shifts, Holidays, Late Deduction, Announcements, FAQs, Documents, Training (and attendees), Company Policy, My Leave and Report Absence.

Used today on: **Approve run** (payroll), the **next month/next year** arrows in attendance history, and **Capture** when clocking in.

### A chart bug found on the way

Opening a payroll run right after the page loads removed the charts' canvases while they were still animating. Chart.js then drew one more frame on a destroyed chart (`Cannot read properties of null (reading 'save')`). `charts.js` now stops the animation and destroys the chart on the next frame.

## Button colours

The theme is blue and grays. A screen or modal has **one** solid primary action; everything else is quieter.

| Variant | Use for | Example |
| --- | --- | --- |
| `primary` (blue) | The one main action | Approve, Save, Send request, Approve run |
| `dark` | Toolbar "create" actions and row actions in tables | Add employee, Confirm payment, Mark load sent |
| `secondary` | Everything else, including the submit of a small inline form inside a modal | CSV, Generate all, Upload, Add adjustment, Add deduction |
| `danger-outline` | A destructive *alternative* next to the main action | Reject beside Approve, Turn off 2FA |
| `danger` (solid red) | Only the final "yes" inside a confirmation modal | Reject (in the reject-reason modal), Return document |
| `ghost` | Cancel | Cancel |

There is no green button variant. Two loud colours side by side compete for attention, and green isn't in the palette. Green stays for status only (badges like "On file" or "Load sent"). `tests/Unit/UiButtonTest.php` fails if `variant="success"` comes back.
