# 24. Patrol checkpoints and time away from the post

Proof that a guard is working **between** clock-in and clock-out, so the office can tell a roving guard checking posts from one at an appointment, or one just hanging around.

## 1. Patrol checkpoints (roving guards)

**Set up** (Operations: Patrols → **Checkpoints**, `manage-patrols`):

1. Choose the client, then **Add checkpoint** for each spot a roving guard must visit (Main gate, Parking B1 ramp, Fire exit…). Coordinates are optional. With them, a scan must be within **50 m** of that spot (`attendance.patrol.checkpoint_radius_meters`). Without them, it must be inside the site's geofence.
2. Set **A full round every (min)**, 120 by default: every checkpoint must be scanned once in each window, counted from the start of the duty (06:00–08:00, 08:00–10:00…).
3. **Print QR stickers:** a printable A4 sheet, cut along the dashed lines and stick each sticker at its spot. Each QR holds a random code (`OSSI-CP:…`), so a checkpoint can't be guessed or made up. Renaming keeps the code, so stickers already up keep working.
4. On the **Duty Roster**, set the duty's **Type of duty** to **Roving patrol** (the other card is *Stays at the post*). The dialog shows what that means at the chosen client: "3 checkpoints, a full round every 2 hours", or an amber warning with a link to Patrols when the client has no checkpoints yet. A post named "Roving" gets it automatically when filling the week.

A guard can scan only the stickers of **their post today** (the duty's client). A sticker from another client is refused with both names: "Parking B2 is at Metrobank – Dasmariñas. Your post today is OSSI Test Site (QR): scan the stickers there." On the phone, a refusal shows in red and the scanner waits 4 seconds before reading again; if the scan never reaches the server (no signal, server error) it says so instead of staying on "Saving the scan…".

**On duty** (the guard, on My Attendance): the **Patrol** card shows the current round, which checkpoints are done, and **Scan checkpoint**. The back camera opens and reads the QR (the browser's built-in reader where available, the `jsqr` library elsewhere, e.g. iPhone). Each scan saves:

- the time (server clock),
- the GPS position, which must be accurate to ±100 m and within reach of the checkpoint,
- **the camera frame that read the QR** (a photo of the spot, so scanning a photo of the sticker shows),

and only works while clocked in, at the client of that day's duty. The same checkpoint again within 5 minutes is the same scan.

**Rounds** (`PatrolRoundsQuery`): a round is **done** when every checkpoint was scanned in its window, **missed** when its window ended (or the guard clocked out) without all of them, **current**, or **upcoming**. Rounds that ended before the guard clocked in don't count against them.

### Stopping fake scans

A photo of a sticker scanned from **elsewhere** fails the GPS check. The risk is a guard who stays **on site** (e.g. in the guardhouse) and scans photos of every sticker instead of walking the route. Four defences:

1. **Pin each checkpoint at its sticker.** Edit → **Use my location here** while standing at it (shows the GPS accuracy; a rough fix says so). A pinned checkpoint accepts scans only within **50 m** of it (`patrol.checkpoint_radius_meters`, plus the phone's accuracy, capped). The list shows **Pinned**, or amber **Not pinned: scans anywhere on site**. Leave deep basements with weak GPS unpinned.
2. **Suspicious rounds** (`PatrolRoundsQuery`): a round of 3+ checkpoints is flagged when it was scanned faster than 20 s per checkpoint (`suspicious_seconds_per_checkpoint`), or when all scans came from one spot (within 15 m, `suspicious_same_spot_meters`) while the pinned checkpoints are at least 40 m apart (`suspicious_spread_meters`). Patrols shows the round chip with a red ring and "!" plus "Round 2 looks faked: 4 checkpoints scanned in 12 seconds". Operations gets one alert per round ("Round 2 looks faked at …").
3. **Scan photos:** **Scans** on a guard's row opens today's scans with the camera frame of each (click to enlarge). A photo of a phone screen (edge, glare, pixels) shows. Photos are private (`/patrol-scans/{scan}/photo`, `PatrolScanPolicy`: the guard, or Operations within its region).
4. **New sticker:** gives a checkpoint a new code (`RenewCheckpointCode`), so the old sticker, and every photo of it, stops scanning. Print and swap it. Use it when cheating is suspected, or monthly.

A "fake GPS" app on Android can't be reliably detected by a web app; #2 and #3 still catch it, because a faked round still looks wrong. For high-risk sites, NFC tags (tap, can't be photographed; Android Chrome only) are a later option. Tests: `PatrolAntiCheatTest.php`.

## 2. Away from post (everyone on duty)

**The idea:** traffic, a bank queue or an errand that grows is normal work, so time away is measured against **when the person said they'd be back**, not a fixed limit. Running late is only a heads-up; a supervisor is alerted when **nobody knows where they are**, or they're **well past their own time without asking for more**. There is **no meal break** here: it's already part of the duty.

- **Leaving post** on My Attendance → why → **how long will you be out?** → a note → **Mark me away**. **They choose their own time; nothing is suggested.** The reasons show no hours and "How long" starts on *Choose your time*: without one, it's refused ("Choose how long you'll be out. Coming back early is fine."). A suggested "usually 2 hrs" became the plan, even for a 20-minute bank errand, and was never late. Now they're held to their own word: saying 30 minutes and taking 2 hours shows as running late. The note's example follows the reason.

  Each reason still has a usual length in the code (`AwayReason::usualMinutes()`), used **only** as a fallback for absences recorded without a time (older rows):

  | Reason | Usual (fallback only) |
  | --- | --- |
  | Checking other posts | 30 min |
  | Errand for the client | 1 hr |
  | Called by supervisor | 30 min |
  | Appointment (note required) | 2 hrs |
  | Personal (note required) | 30 min |

  Or they pick 15 min, 30 min, 1 hr, 1 hr 30 min, 2, 3 or 4 hrs (`away.durations`). This sets `away_logs.expected_back_at`: "back by 11:00 AM".
- For **personal reasons only** (Appointment, Personal: `AwayReason::isPersonal()`), "How long" adds: *Out for more than 4 hrs? File it as leave on My Leave instead* (`AwayReason::SUGGEST_LEAVE_AFTER_MINUTES`). Real time off goes through Leave, where it's approved and counted. A long **official** trip (a day at PNP-SOSIA, three sites) is still work, so it never gets the hint. (Leave is whole days; there's no half-day leave yet.) Emergencies go under Personal, with a longer time picked and a note.
- Which reasons need a note is `AwayReason::needsNote()`: all but a guard's routine ones (checking other posts, an errand for the client, called by the supervisor), which are marked "(optional)".
- **The note asks what's useful for the reason** (`AwayReason::noteLabel()` / `noteExample()`), instead of one "Note (where, who asked)":

  | Reason | The note asks | Example |
  | --- | --- | --- |
  | Official business | Where, and what for | BDO Ortigas, depositing payroll |
  | Client site visit | Which site, and what for | Makati CBD, inspecting Gate 2 |
  | Government office | Which office, and what for | SSS Pasig, remitting contributions |
  | Appointment | Where (no need to say what for) | Robinsons Galleria |
  | Personal | Where | ATM at SM Megamall |
  | Errand for the client | Who asked, and what for | Building admin, receiving a delivery |
  | Called by supervisor | Who called you | Sgt. Reyes, at the detachment |
  | Checking other posts | Which posts | Gate 2 and Parking B |

  "What for" is what the approver needs on a work trip. "Who" only where someone sent them. On an appointment, the purpose (dentist, doctor) is health information, sensitive personal information under RA 10173, and nobody needs it to approve a trip out, so the label says not to give it.
- **Need more time?** While away, they add +15 min, +30 min, +1 hr or +2 hrs (`away.extensions`) with a short reason ("traffic on EDSA", "long queue at BIR"). **No approval and no alert**: it just moves their time, and the supervisor sees it on the timeline (`away_extensions`, `ExtendAway`). Added from the current time when they're already late, so "+30 min" means 30 more minutes. One absence can't be planned or stretched past 8 hours from leaving (`away.max_minutes`).
- **Location:** every **15 minutes** (`away.location_update_minutes`) the card shows an amber **Time to share your location** reminder and **Update my location** becomes the main button. It's a reminder, not a warning. Only after **30 minutes** with nothing shared (`away.location_alert_minutes`) does it turn red, saying the supervisor has been told. Each update saves the GPS position, its accuracy, and the distance from the post (`away_location_updates`). A rough fix is saved with its accuracy rather than refused.
- **Back at post** always works. It isn't blocked by the geofence, because an errand can end anywhere and GPS can be off indoors. Where it was tapped, and how far that is from the post, is recorded (`returned_distance_meters`), and Operations sees "back tapped 10.1 km from post" when it's far.
- The card stays amber, showing since when, how long, and **back by**. Past that time it says "Running late? That's fine: add time below so Operations knows."
- **Clocking out** ends an absence that's still open (the clock-out itself must be inside the geofence).
- A supervisor can **Approve** an absence at any time, even in advance for a known long errand. It then isn't flagged as late. Nobody approves their own.

## Operations' view (Patrols → On duty now)

- **Away from post:** who, why, the note, **time out** ("10:14 AM → back by 12:14 PM", then a badge: gray "35 min out", amber "20 min past their time", red once it's an hour past, blue "approved", and "added time once"), the **last location** (time, distance from the post, a **Map** link with its accuracy, and a red **Location overdue** badge after 30 silent minutes), and **Approve**. **Back at post today** lists today's completed absences ("Back on time", or amber "Back 34 min after 12:14 PM"), including any return tapped far from the post.
- **Names open the person's employee record** (dotted underline; `<x-person-link>`, for viewers with `view-people`). The same link is on the DTR, Attendance Log, Leave and Overtime Requests, Duty Roster, Relievers, Borrow Load, Complaints, Patrols and the client's guard list. The location window also has **View … profile**.
- **View location** opens the **location window** (`AwayTrailQuery`). A status line on top gives the verdict in the list's colours ("Back 14 min after their time · 57 min out · said back by 2:55 PM · added time once"). The map opens fitted to the **whole trail**, with the post or office included only when it's within 3 km. **Whole trail** and **Office/Post** (marked "far" when it's far) re-fit it, and **clicking a timeline entry centres the map on that spot**. The timeline sits under the map: each entry shows the minutes since the one before ("+16 min", amber once it's 30 or more), GPS accuracy as a small dot (in words only when weak), and a Google Maps icon. The window contains:
  - **the map** (Leaflet + OpenStreetMap): the post and its geofence circle in blue, the latest spot in amber with its GPS-accuracy circle, earlier points in gray, and the dashed path between them;
  - **last seen:** time, minutes ago, distance from the post, the **street address** of that spot (looked up once and cached; only the latest spot, as OpenStreetMap asks), GPS accuracy, overdue/over-limit badges, and **Open in Google Maps**;
  - **said back by**, and **the timeline:** left the post (with the time they first said) → each shared location and each **Needed more time** ("+30 min: traffic on EDSA (back by 12:44 PM)") → back at post, with distance, accuracy and a map link;
  - **Approve time away**, while it's still open.

  A Satellite Operation can only open its region's guards.
- **Roving guards on patrol:** each round as a numbered chip (blue done, red missed, amber in progress, gray later), how many checkpoints are scanned now, and the last scan.
- Refreshes every minute. A Satellite Operation sees its region only. The sidebar badge counts guards away right now.

### Sorted for a big workforce

The same on Patrols and Office Away, so the list stays readable with hundreds out:

- **Status tabs** with counts: All · **Needs attention** (no location for 30 min, or an hour past their own time; red) · Past their time (amber) · Location overdue (red) · Approved. Whoever needs attention is always listed first, then the longest out.
- **Filters:** a name search, the reason, and (guards only, for viewers who see every region) the **region** of the post they're rostered at today, else of the client they're deployed to. A Satellite Operation has no region filter: it already sees one region.
- **Back today** is its own card with the real total ("Back at post today · 37"), 15 a page, the same filters, and a status per row (approved by…, back after their time, back on time).
- **Speed:** only open absences are loaded for the list (a small set at any moment). `away_logs` has indexes on `(returned_at, left_at)` for "who is out now" (the list, the sidebar badges and the 15-minute alerts) and on `left_at` for "back today", which is asked as a time range, not `whereDate()`, so the index is used (migration `2026_10_12_100002`).

The client's **Today** view shows the same: **Away · Appointment** (since, back by, note; red only when it needs attention), and **On duty · round missed** in red.

## 3. Office staff stepping out (their head monitors)

Office staff do the same thing, in office words, and **their head** watches it (`App\Support\Heads`): HR for Admin, Accounting and Operation HQ; Operation HQ for a Satellite; the President or VP for HR. The President or VP also see everyone's:

- My Attendance → **Stepping out of the office** → why → how long → a note → **Mark me out**. The office reasons, all needing a note (the hours are the fallback only, never shown):

  | Reason | Fallback | For |
  | --- | --- | --- |
  | Official business | 2 hrs | the bank, a client meeting ("BDO Ortigas, depositing payroll") |
  | Client site visit | 3 hrs | a client's post or detachment: inspecting guards, delivering uniforms or payroll, an incident ("Makati CBD, inspecting Gate 2"). Its own reason because it takes longer than the bank, and the President or Vice President can see (and filter) how much time goes into supervising posts |
  | Government office | 3 hrs | SSS, PhilHealth, Pag-IBIG, BIR, DOLE, PNP-SOSIA: remittances, filings, licenses ("SSS Pasig, remitting contributions"). Its own reason because it rarely fits in 2 hours, and the President or Vice President sees at a glance why someone's out half the morning |
  | Appointment | 2 hrs | doctor, dentist |
  | Personal | 30 min | ATM, pharmacy |

  No lunch reason: the meal hour is inside the 9-hour duty, so going out to eat isn't logged. Guard reasons like "Checking other posts" are refused for office staff, and office reasons for guards.
- While out, they can add time and are reminded to share their location every 15 minutes, as guards are. The distance is measured from the **head office** (`attendance.office`; set `OFFICE_NAME`, `OFFICE_LATITUDE`, `OFFICE_LONGITUDE`; the circle around it is 150 m). Without the coordinates, updates are still saved, just without a distance.
- **Back at the office** ends it; clocking out does too.
- **Office Away** (sidebar, `monitor-office-away`: President, Vice President, HR, Operation HQ and Super Admin; HR and Operation HQ see only the people they head, and only the head may **Approve**) lists the office staff out right now: reason, note, since when, last location, position, and **Approve**. The sidebar badge counts them. **View location** opens the same map window, with the office instead of a post. "Back at the office today" lists the finished ones.
- **Alerts** for office staff (no location for 30 min, or an hour past their own time) go to **their head**, with a link to Office Away. Operations doesn't get them, and Patrols lists guards only.
- Operations can't open Office Away or approve an office absence; the President or Vice President can't approve their own.

## Keeping it on record

Time away is attendance, so it's **kept, not cleared**. The screens just show today by default.

- **History by day:** the "Back today" card has a day picker (‹ date ›, and **Today**). Pick any earlier day to see that day's absences, with the same filters, paging and window ("Time away on Sat, Oct 10, 2026 · 3"). A typed URL can't ask for a future day.
- **On the employee record:** **Time away · last 30 days** lists each absence (day, reason, times, note, status) with totals ("4 times, 210 min in all · back after their time 1× · never ended 1×"). It shows the summary only; the map stays on Patrols / Office Away.
- **Forgotten absences:** if nobody taps *Back* and they never clock out, `app:close-forgotten-aways` (every 15 min, `CloseForgottenAways`) closes it **an hour after the duty's scheduled end** (`away.auto_close_after_minutes`). It closes it **at** that end and sets `away_logs.auto_closed_at`. The lists show red **"Never ended: closed at duty end"** so HR can follow up. Clocking out still ends an absence as before. **Clocking in** also closes any absence still open from an earlier duty, the same way (at that duty's end, flagged), so a forgotten "away" from yesterday never hides today's patrol scanner, even where the scheduler isn't running (`RecordPunch::clockIn`).
- **Deleting the GPS trail (Data Privacy Act, RA 10173):** `app:prune-away-locations` (02:30 nightly, `PruneAwayLocations`) deletes, for absences older than `away.location_retention_days` (90, env `ATTENDANCE_AWAY_LOCATION_RETENTION_DAYS`), every shared GPS point and the coordinates where *left* and *back* were tapped, then sets `locations_pruned_at`. **Kept:** reason, note, times, "said back by", added time, approval and distances. Street addresses are never stored (looked up live, cached a day). The window then shows "Location trail deleted" instead of the map.
- The **privacy notice** (version `2026-10-13.1`) now says when location is captured (clock in/out, patrol scans, away and back, "Update my location"), who sees it, and the 90-day deletion. Set `PRIVACY_NOTICE_VERSION=2026-10-13.1` in `.env` so everyone is re-prompted.
- All absences share one status, `AwayLog::outcome()`, for the badge on the lists, the window and the employee record.

## Alerts

`php artisan app:send-patrol-alerts`, every **15 minutes** (`routes/console.php`). It sends bell and email to Operation HQ and the region's Satellite Operation (to the office staffer's head for office staff), **once each** (claimed in `sent_alerts`):

- "Round 3 missed (10:00–12:00 AM) at Makati CBD. Not scanned: Parking B1 ramp."
- "Not back yet: 60 min past 11:00 AM (Errand for the client)… no more time asked. Call them, or approve it on Patrols." Sent once per expected time (`away.late_alert_minutes`, 60): adding time and then running an hour past the new time alerts again.
- "No location shared for 31 min while away (Errand for the client). Last seen 10:10 AM; expected back by 11:00 AM." (`away.location_alert_minutes`, 30). Sent once per silence: a new update, and then another silence, alerts again.

Running a little late, adding time, and the 15-minute share reminder are **never** alerts.

## Configuration (`config/attendance.php`)

- `patrol.checkpoint_radius_meters` (75)
- `patrol.repeat_scan_minutes` (5)
- `away.durations` (15 min to 4 hrs) and `away.extensions` (+15 min to +2 hrs): the choices offered; the usual length per reason is `AwayReason::usualMinutes()`
- `away.max_minutes` (480): the longest one absence can be planned or extended to
- `away.location_update_minutes` (15, env `ATTENDANCE_AWAY_LOCATION_MINUTES`): the reminder on their phone
- `away.location_alert_minutes` (30, env `ATTENDANCE_AWAY_SILENT_MINUTES`): silence before the supervisor is alerted
- `away.late_alert_minutes` (60, env `ATTENDANCE_AWAY_LATE_MINUTES`): past their own time before the supervisor is alerted
- `office` (name, latitude, longitude, radius_meters 150; env `OFFICE_*`): the head office that office staff's distance is measured from.
- The per-client round interval is on Patrols.

## What this doesn't do (yet)

- **Pay is not changed** by time away or missed rounds: they're shown and alerted, and HR/Operations decide. Deducting unapproved time away would be a separate payroll rule.
- **No reminders on a closed phone:** the app can't buzz a guard while the app is closed. Push notifications, or SMS (a paid service), would add "round due in 10 minutes" reminders and periodic "still at post" check-ins.

## Code

- Tables (migration `2026_10_10_100005`):
  - `patrol_checkpoints`, `patrol_scans`, `away_logs`
  - `clients.patrol_interval_minutes`, `duty_assignments.patrols`
  - later: `away_location_updates` (`2026_10_12_100001`), indexes (`…100002`), `away_logs.expected_back_at` and `away_extensions` (`…100003`)
- `AwayLog`: `expectedBackAt()`, `minutesPastExpected()`, `isPastExpected()` (amber), `isLate()` (an hour past, still out, unapproved), `isLocationDue()` (the phone reminder), `isLocationOverdue()` (30 silent min), `needsAttention()` (late or silent: the red ones).
- Actions:
  - patrols: `RecordPatrolScan`, `SaveCheckpoint`, `RemoveCheckpoint`, `SetPatrolInterval`
  - away: `LeavePost` (with the chosen length), `ExtendAway`, `UpdateAwayLocation`, `ReturnToPost`, `ApproveAway`
  - alerts: `SendPatrolAlerts` (command `app:send-patrol-alerts`, notification `PatrolAlert`)
- `PatrolRoundsQuery`; `ClientAttendanceTodayQuery` shows the away and missed-round states.
- Pages: `App\Livewire\Admin\Patrols`; `App\Livewire\Admin\AwayMonitor` (the away list and location window, with `AwayTrailQuery` and `resources/js/away-map.js`: embedded on Patrols with `scope="field"`, and the full Office Away page with `scope="office"`); `PrintCheckpointsController` (QR SVGs from `bacon/bacon-qr-code`), `ClockPanel` (the patrol card, scanner and away buttons), `resources/js/patrol-scanner.js`.
- `PatrolSeeder`: four demo checkpoints at each site with a "Roving" post, and the roster's Roving duties marked as patrol duties.

Tests: `tests/Feature/Attendance/PatrolsTest.php` and `OfficeAwayTest.php` (office reasons, distance from the office, the President or Vice President monitoring and approving, alerts to the President or Vice President only). They cover:
- the scan record with its photo
- made-up codes, another site's sticker, scanning from far away, and not clocked in
- repeat scans
- done and missed rounds
- missed-round alerts (once, to the right region)
- leave and return (note required; a far return is recorded, not blocked)
- location updates while away: the 15-min reminder alerts nobody; the 30-min silence alerts once
- the expected return: usual length per reason or a chosen one, amber past it, one alert an hour past it
- adding time (allowed lengths, a reason required, from now when late, the 8-hour cap), re-arming the lateness alert, and the timeline
- the location window (address, timeline, approve; another region refused)
- the status tabs (needs attention first), the reason, name and region filters, and the paged "back today" list with its total
- approval clearing the lateness flag (not by the guard themselves)
- history by day, closing forgotten absences an hour after the duty (or at the next clock-in), deleting the GPS trail after 90 days while keeping the summary, and the employee record's last 30 days (`AwayHistoryTest.php`)
- clock-out ending an absence
- the Today view
- adding a checkpoint and the QR print sheet
- Satellite's region

## Try it

1. As `opshq@example.test`: Patrols → Checkpoints → BGC Tower One or Makati CBD → **Print QR stickers**.
2. As a roving guard on duty now (Duty Roster: a "Roving" post, Type of duty **Roving patrol**), on a phone: My Attendance → **Scan checkpoint** → point at a printed sticker.
3. Same guard: **Leaving post** → Appointment → How long: 30 min → "Dentist at Glorietta" → **Mark me away**. Then **Need more time?** → +30 min → "Long queue" → **Add time**. As `opshq@example.test`: Patrols shows them away with the new "back by", the timeline shows why, and **Approve**.
4. As `accounting@example.test`, clocked in: **Stepping out of the office** → Official business (usually 2 hrs) → "BDO Ortigas, depositing payroll" → **Mark me out** → **Update my location**. As `executive@example.test` (the President): **Office Away** → **View location** → **Approve time away**.
