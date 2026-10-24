# 23. Duty Roster, dynamic attendance and anti-cheating

Most guards don't keep one fixed shift, so the schedule is set **per day** on the **Duty Roster**, and every clock-in is matched to that day's duty. Late, undertime, overtime and night differential follow the duty, and the rules below stop the usual ways to cheat.

## The Duty Roster (`manage-roster`: Operation HQ, Satellite Operation in its region, Super Admin)

- **A week grid:** one row per guard, one cell per day. Filter by client; move between weeks with the arrows.
  - **Blue cell:** a duty (time range and post).
  - **Purple:** a relief duty (set on Relievers).
  - **Dashed "Off":** a day off.
  - **Past days** show what actually happened: in → out times, minutes late, **Absent**, or **Manual**.
- **Click a day** (today or later) to set the duty: a **duty template** (Shifts), client and post. **Make it a day off** removes it.
  - **Custom time…** (last in the Duty list): type the hours instead, in the same box HR uses on the employee record (`<x-shift-hours>`). It has common hours in one tap, Start / End, "9 hours · ends the next day", and the grace before late. It starts from the duty picked before, so 9–6 → 10–7 is one change. Saved through `ShiftForTimes`: an existing shift with exactly those times and grace is reused (22:00–07:00 is Night Duty), otherwise a new shift is made, named by its hours ("9:00 AM – 6:00 PM"; "· 10 min grace" is added if those hours exist with another grace). It then shows in the list and on Shifts. Until 2026-10-24 every new one was named "Custom": shift names are unique, so a second set of typed hours crashed (Add employee on the pilot).
- **Fill the empty days of this week**, without touching days already set or past days:
  - **Copy last week:** each guard's duties from the week before (shift, client, post).
  - **Usual shifts, Sunday off:** Monday to Saturday on each guard's usual shift and post.
- **Refused:** past days, a duty already clocked in, and relief duties (change those on Relievers).
- **Duty templates** are 9 hours (8 hours of work + the 1-hour meal break): **Day Duty 06:00–15:00**, **Mid Duty 14:00–23:00**, **Night Duty 22:00–07:00** (`ShiftSeeder`, created on every install). Super Admin adds more on Shifts.

## How a clock-in finds its duty (`CurrentDutyQuery`)

1. **A rostered duty whose window holds "now":** from **60 minutes before its start** (`early_clock_in_minutes`) until its end. Yesterday's duty counts too, so a guard late for a 22:00–07:00 duty can still clock in at 00:30, and that day is recorded against last night.
2. **On the roster but no duty now:** the clock-in is refused, saying why:
   - "Your duty today starts at 10:00 PM. You can clock in from 9:00 PM."
   - "Your duty today ended at 3:00 PM."
   - "You have no duty today… Your next duty: Tue, Oct 13 at 6:00 AM, Makati CBD · Lobby."
   My Attendance shows the same message and locks Clock In.
3. **Never rostered** (no duty within 7 days, `roster_window_days`): the guard clocks in on their **usual shift** from the employee record, as before. Nothing breaks before rosters are filled, and office staff keep working as they did.

My Attendance shows the duty ("Duty: Day Duty · 06:00 – 15:00", the post and its geofence) and **My duties this week**.

## Anti-cheating

| Trick | What stops it | Where |
| --- | --- | --- |
| Clocking in early to pad hours | Allowed from 60 min before, but **nothing counts early**: late is measured from the duty's start, night differential only inside the duty, and overtime is paid only when approved | `AttendanceCalculator`, payroll |
| Clocking in from home | **Geofence:** the punch must be within the client's radius (default 150 m) of its coordinates | `RecordPunch::ensureAtPost()`, `Geofence` |
| Clocking out from home (leaving early) | The geofence applies to **clock-out too**, at the post of the duty being closed | same |
| A vague GPS fix to fake being close | A fix worse than **±100 m** is refused; the phone's stated error only widens the fence by 50 m at most | `max_gps_accuracy_meters`, `Geofence::isInside()` |
| Clocking in on a day off | Refused on the roster; Operations must add the duty first | `CurrentDutyQuery` |
| Someone else clocking in | Selfie with time, place and coordinates burned in (unchanged) | `clock-capture.js` |
| Changing the phone's clock | Server time only (unchanged) | `RecordPunch` |
| Two taps, two clock-ins | Row lock + unique (user, day) index (unchanged) | `RecordPunch` |

**Waiting for a precise fix:** a phone's first GPS reading is often rough (±100 m or worse, from Wi-Fi or cell towers) and sharpens within seconds. So nothing is sent on the first reading:

- the punch camera keeps **Capture** locked, saying "Getting a precise GPS fix (now ±150 m, needs ±100 m)";
- the QR scanner holds a read until the fix is good;
- **Back at post** and **Leaving post** watch the position for up to 20 s (8 s for leaving) and send the best reading, showing "Getting a precise GPS fix (±150 m)…".

Passing GPS errors between readings are ignored; only a denied location permission stops it.

A client **without coordinates has no geofence yet**: Clients shows "No geofence yet" in amber. Set the coordinates on **Clients → Edit**: right-click the site in Google Maps, click the numbers to copy them, and paste them as "14.5547, 121.0244". Set a radius from 30 to 2,000 m.

## When a guard couldn't punch: manual attendance

A broken phone, no GPS, a post without signal. On the Duty Roster, a past or today's duty without a punch shows **Record by hand**:

- Clock-in, optional clock-out, and a **reason** (required, at least a few words).
- The times must be near the duty, not in the future, out after in, and at most 24 hours.
- Marked **Manual** on the roster, with `recorded_by` and `manual_reason` on the record, and audited (`attendance.manual`) with the name and reason.
- **Never over a selfie punch:** a real punch is evidence and stays as it is.

## Relievers get a duty

Assigning a reliever (`AssignReliever`) creates a **relief duty** for them that day: the absent guard's duty (or usual shift) at the absent guard's client and post. That's what lets the reliever clock in **there**, inside that post's geofence. Changing the reliever moves the duty, unless the first one already clocked in. Guards with their own duty that day aren't offered as relievers: relievers come from their day off.

## The client view follows the roster

On Clients → a client's guards, the **Today** column uses each guard's duty for the day. A rostered guard with no duty today shows **Day off** instead of "No clock-in".

## Patrols and time away

A duty's **Type of duty** is *Stays at the post* or **Roving patrol** (two cards in the duty dialog, with the client's checkpoint count and round time shown under Roving): the guard must scan the site's QR checkpoints every round. Any guard on duty logs **Leaving post / Back at post** with a reason. See [Patrol checkpoints and time away](24-patrols-and-away.md).

## Code

- Tables (migration `2026_10_10_100004`):
  - `duty_assignments` (unique per guard and day)
  - `clients.latitude/longitude/geofence_meters`
  - `time_logs.duty_assignment_id`, `recorded_by` and `manual_reason`
- `CurrentDutyQuery`, `DutyForPunch`, `Geofence`, `RecordPunch`
- `SaveDuty`, `RemoveDuty`, `FillRosterWeek`, `RecordManualAttendance`, `RosterRules`
- `App\Livewire\Admin\DutyRoster`, `App\Livewire\Attendance\ClockPanel`
- Config `config/attendance.php`:
  - `early_clock_in_minutes` (60)
  - `roster_window_days` (7)
  - `max_gps_accuracy_meters` (100)
  - `default_geofence_meters` (150)
- Seeders:
  - `ShiftSeeder`: the duty templates
  - `ClientSeeder`: demo coordinates
  - `RosterSeeder`: two weeks of duties from today, with staggered rest days. Maria keeps her 09:00–18:00 and works today, so the demo clock-in works.

Locally, the **Dev only: simulate capture** button punches from the post's coordinates, so the geofence lets it through. It doesn't exist in production.

Tests: `tests/Feature/Attendance/DutyRosterTest.php`. They cover:
- early clock-in allowed but not counted
- lateness from the duty
- too early refused
- a day off refused, with the next duty named
- a night duty clocked in after midnight
- unrostered guards keeping their usual shift
- the geofence on clock-in and clock-out
- the GPS accuracy limits
- relief duties and the reliever clocking in at the absent guard's post
- busy guards not offered as relievers
- the roster page (set, fill, past and clocked-in refused, Satellite's region)
- manual attendance, which never overwrites a selfie punch

## Try it

1. As `opshq@example.test`: **Duty Roster**. Click a guard's day and change it to Night Duty; try **Copy last week** on next week.
2. As Maria (`employee@example.test`): My Attendance shows today's duty and the week. **Clock In** → *Dev only: simulate capture* works, because it stands at SM Megamall. On a phone away from the post, the clock-in is refused with the distance.
3. As `opshq@example.test`: on a past day with **Absent**, click **Record by hand**.
