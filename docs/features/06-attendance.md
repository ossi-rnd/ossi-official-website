# 6. Attendance: clock in/out with selfie and GPS

> **Schedules are per day now.** A clock-in is matched to the guard's duty on the **Duty Roster**, must be inside the post's **geofence**, and needs a GPS fix of ±100 m or better. A guard never rostered keeps their usual shift. See [Duty Roster and anti-cheating](23-duty-roster.md).

**Front or back camera.** The camera opens on the front camera (the selfie). On a phone with two cameras, a **Back camera / Front camera** button on the preview switches between them. Phones open one camera at a time, so the current one is stopped first, and Capture waits until the new one plays. The preview is mirrored only for the front camera; the back camera shows the scene as it is. The saved photo is never mirrored. A back-camera photo usually doesn't show the face, so the supervisor reviewing it relies on the timemark and the post instead.

## Flow

```
Browser (Alpine: resources/js/clock-capture.js)
  camera (front by default; "Back camera" switches, getUserMedia) + GPS (watchPosition) + address preview
  → canvas: downscale to 960 px, burn in timemark, JPEG 0.82 (~150 KB)
  → $wire.submitPunch(type, photo, lat, lon, accuracy, address)
        │
ClockPanel::submitPunch()  returns {ok, message}
        │
RecordPunch::handle()
  1. validate coordinates
  2. PunchPhotoStore: decode base64, check REAL bytes are JPEG/PNG, ≤ 5 MB, private disk
  3. DB transaction:
       clock in : lock today's log; none → create (late minutes, status)
       clock out: lock the open log (today, or last night's if the shift crosses midnight)
                  → undertime, overtime, final status
       create the punch row
  4. failure anywhere → delete the photo, rethrow
```

## The rules (spec) and where they live

| Rule | Code |
| --- | --- |
| One log per person per day | unique `(user_id, log_date)` index **and** the lock |
| Two rapid taps = one punch | `lockForUpdate()`; for clock-in (no row yet) the unique index catches the race → `UniqueConstraintViolationException` → "already clocked in" |
| No photo or GPS → refused | `PunchPhotoStore`, `ensureValidCoordinates()` |
| Late = minutes after start + grace, never negative | `AttendanceCalculator::lateMinutes()` |
| Undertime = minutes before shift end; overtime = worked − scheduled | `AttendanceCalculator` |
| Status priority | `TimeLogStatus::fromMinutes()` |
| Failed geocoding never blocks the punch | address saved as null |
| Photos only for staff with supervise-attendance | `TimeLogPunchPolicy` (plus the employee's own) |
| 20 address lookups per user per minute | `throttle:reverse-geocode` |

## Design notes

- **The caller states the punch type.** If the server guessed "clock-in or clock-out?", a double-tapped clock-in would silently become a clock-out.
- **`AttendanceCalculator` is pure**: every time is passed in, so a test can say "09:15 on a 09:00 + 10 shift" directly. An arch test forbids it from using facades or models.
- **`ReverseGeocoder` is an interface.** Tests bind `FakeReverseGeocoder`; production uses Nominatim, cached by coordinates rounded to ~11 m (its usage policy allows about 1 request/second).
- **Why the method returns an array**: the camera modal is `wire:ignore` (a re-render would reset the live video), so it can't display Blade errors.
- **Livewire's 1 MB payload limit** is why the photo is downscaled in the browser.
- **The camera stops** when the modal closes (`destroy()` stops tracks and the GPS watch). The old app left it running.
- **HTTPS is required** for camera and GPS: `herd secure ossi_official_site` locally. Chrome also treats `localhost` as secure.
- In local mode a dashed "simulate capture" button lets you punch from a desktop without a camera.

## Alerts when a clock-in or clock-out is missing

| Case | First | Then |
|---|---|---|
| Guard on the roster, no clock-in after the grace period | `DetectNoShows`: Operations (region's Satellite, then Operation HQ), Relievers board | — |
| Anyone still clocked in after the shift | the employee, `clock_out_reminder_minutes` after the shift end | their head, `clock_out_escalate_minutes` after |
| Office staffer, no clock-in on a work day | the employee, grace + `office_clock_in_reminder_minutes` after the start | one summary bell per head per batch, naming only their people |

**Who is the head** (`App\Support\Heads`, the owner's chain, 2026-10-23, shared with leave, overtime, time away and Notices to Explain): a guard → Operation HQ and the Satellite of their client's region; HR, Operation HQ and Accounting → the President or VP; a Satellite Operation → Operation HQ; any other office staff (Admin, …) → HR. Never the person themselves. HR is also told, as timekeeper, about office staff it doesn't head (Operation HQ, Accounting, Satellites; `Heads::toTell`), never about other HR staff.

`SendAttendanceAlerts` (`app:send-attendance-alerts`, every 5 minutes) does the last two rows. It skips approved leave, holidays, and days that aren't office work days. Each alert is claimed once in `sent_alerts`. The numbers and office work days are HR's: Attendance Log → **Alert rules** (`App\Support\AttendanceAlertRules`, `SaveAttendanceAlertRules`, audited; starting values in `config/attendance.php` `alerts`). Office staff have no roster, so the work days are what tell a rest day from a missed one.

## Screens

- **My Attendance**: today card, punch modal, history (7/15/30 days, monthly DTR, yearly rollup with charts, CSV export) and the punch log with photos.
- **Company phone per guard** (2026-10-21; flag only, never refused). The first clock-in registers the phone (`user_devices`: one per person). The phone is told apart by a random 40-character code in an encrypted, HttpOnly, five-year cookie (`App\Support\DeviceCookie`, `ossi_device`), stored only as its SHA-256; a website can't read an IMEI. Each punch keeps `device_hash`, `device_label` ("Android · Chrome", `DeviceLabel::fromUserAgent`) and `other_device`. **Brand and model** where the browser gives them: `clock-capture.js` asks User-Agent Client Hints for the model (`navigator.userAgentData.getHighEntropyValues(['model'])`; Chrome, Samsung Internet and Edge on Android over HTTPS), and `DeviceLabel` adds the brand from the code's pattern (SM- Samsung, CPH OPPO, RMX realme, V2xxx vivo, 23021RAA2Y-style Xiaomi, …): "Samsung SM-A156E · Chrome". iPhones and Firefox give no model ("iPhone · Safari"). Model codes, not marketing names (no phone database). A registered phone's label updates on its next punch. A **guard's** punch from another phone, or from a browser that keeps no cookie, is saved and flagged (`CheckPunchDevice`); office staff's phones are recorded, never flagged. `ReportOtherPhone` writes the audit log (`attendance.other_phone`) every time and rings the bell (`OtherPhoneUsed`) for Operation HQ, HR and the guard's region's Satellite Operation once a day per phone. Ability `ManageGuardPhones` (Operation HQ, HR, Super Admin; a Satellite for its region): **Make this their phone** from a flagged punch in the Attendance Log (`SetGuardPhone`; earlier punches keep their flag) and **Forget phone** on the employee page (`ForgetGuardPhone`; the next clock-in registers the phone used). Attendance Log view **Other phone** (`?show=other-phone`). Limits: clearing the browser's data or using another browser looks like a new phone. Privacy notice updated (version 2026-10-21.1).
- **One snapshot per punch** (`resources/js/clock-capture.js`): Capture freezes the position, accuracy and an address looked up for **that** position (fetched right then if the last lookup was for a spot more than 25 m away), burns them into the photo, and Submit sends the same snapshot. Before 2026-10-21 Submit sent the live GPS and the latest address, and the address could lag the coordinates (lookups at most every 15 s), so the photo and the record disagreed (seen: photo "Saint Peter Street, Baesa", record "Road 18 Extension, Project 8" for points 4 m apart). The preview shows an address only while it belongs to the current position.
- **Punch Log** (My Attendance, the employee's own): grouped the same way as the Attendance Log (one row per shift under a heading per day, shared `DayHeading`), clock-in and clock-out side by side with selfie, time, place and Google Maps link, lateness and leaving early in words, cards on a phone. Hours and status stay on Attendance History. An open shift says "Not clocked out yet" while it runs and "No clock-out recorded" once its end has passed.
- **Attendance Log** (supervise-attendance): **one row per shift** (a `TimeLog`), clock-in and clock-out side by side, newest day first under a heading per day (Today, Yesterday, then the date); each heading is its own `<tbody>` with a `<th scope="rowgroup">`. Hours worked, lateness ("5 hr 14 min late"), leaving early and overtime in words. **Details** opens both selfies (lightbox), full addresses, Google Maps links (satellite, 3D, Street View; the Maps app on a phone) and GPS accuracy. On a phone the rows become cards. Entries Operations made by hand (no selfie) are listed too.
  - Views: **All**, **On duty now**, **Late**, **Not clocked out**. "On duty" and "Not clocked out" both mean no clock-out yet; which one depends on whether the shift has ended (`AttendanceCalculator::shiftWindow`, so a night shift running past midnight is on duty, not flagged). No extra grace number: past the shift's end without a clock-out is "Not clocked out", shown with "Shift ended 6:00 PM". The view is in the URL (`?show=late`).
  - Why not a colour per punch type: colour alone doesn't pair an "in" with its "out", it would compete with the warning colour (late), and tinted rows read poorly in dark mode and for colour-blind users.

Tests: `tests/Unit/AttendanceCalculatorTest.php`, `tests/Feature/Attendance/RecordPunchTest.php`.
