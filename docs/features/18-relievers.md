# 18. Report Absence and relievers

A guard who **can't report for duty** tells Operations ahead of time, so a **reliever** can be sent to the post. This is not leave: it doesn't excuse the day and doesn't touch payroll. It only makes sure the post is covered. A planned day off still goes through My Leave.

## The flow

1. **Guard:** **Report Absence** (sidebar, only for guards deployed to a post) → duty date (today up to 60 days ahead) and reason → **Notify Operations**.
   - The client, detachment and post are copied onto the notice, so a later move doesn't change where they should have been.
   - One notice per day. Office staff are refused and sent to My Leave.
2. **Operation HQ** (all regions) and the **Satellite Operation of that region** get a bell alert and an email: "Reliever needed Oct 12: Metrobank – Imus · Main Entrance".
3. **Operations:** **Relievers** (badge = posts still needing cover) → **Assign reliever** → choose a guard, with an optional note → **Assign and notify**.
4. The reliever gets a **relief duty** on the Duty Roster at the absent guard's post, so they can clock in there (inside that post's geofence). Changing the reliever moves it, unless the first one already clocked in. Guards with their own duty that day aren't offered.
5. The **reliever** gets a bell alert and an email ("You're relieving Maria Santos at …"); the **absent guard** gets a bell alert ("Your post is covered"). Both see it in the app.
6. The guard can **cancel** while no reliever is assigned yet. After that, Operations has already moved someone, so the guard calls them.

```
open ──Assign reliever──▶ covered ──Change──▶ covered (new reliever)
  └──Cancel (guard)──▶ cancelled
```

## No-shows: a guard who didn't report (`DetectNoShows`)

Not every absence comes with a notice. A guard who forgets (or doesn't bother) must not leave a post empty for hours, so `app:detect-no-shows` runs **every 5 minutes**:

1. **Detect.** A duty **on the roster** whose **grace period has ended**, with **no clock-in, no absence notice and no approved leave**, becomes a **no-show**: an absence notice with `source = no_show`, linked to the missed duty. It goes on the Relievers board like a reported absence, red **No-show**, today's no-shows first. A guard never put on the roster isn't checked: without a roster there's no day off on record, so a missing clock-in can't be told from a rest day. Once the shift has ended there's nothing left to cover, so it's left alone.
2. **Alert.** Operation HQ and the region's Satellite Operation get a bell alert (live) and an email: *"No-show: Juan Dela Cruz · Metrobank – Imus · Main Entrance. No clock-in and no notice for the Day Duty that started 6:00 AM (grace ended 6:10 AM)."*
3. **Hold the post.** If someone is **still clocked in at that client** (the night guard going off at that same post first), the alert says so: *"Pedro Reyes (Night Duty, until 6:00 AM, Main Entrance) is still clocked in there: ask them to stay until the reliever arrives."* (`HoldoverQuery`). The Assign dialog shows it too.
4. **Escalate.** Still nobody assigned **30 minutes** later (from the grace end, or from when the absence surfaced if later; `ATTENDANCE_NO_SHOW_ESCALATE_MINUTES`), Operation HQ is alerted again, once (claimed in `sent_alerts`). This applies to a reported absence still uncovered once the shift has started, too.
5. **Arrived after all.** When the guard clocks in late (`RecordNoShowArrival`, from `RecordPunch`): nobody sent yet → the notice becomes **Arrived late** and Operations is told "nobody needs sending"; a reliever already sent → it stays covered and Operations is told to decide who stays.
6. **A reliever is a guard on a duty too**, and can be a no-show. One sent after the shift began counts only an hour after being sent (`reliever_travel_minutes`).

On the guard's side, a no-show shows on **Report Absence** with a red badge and **can't be cancelled** ("Operations raised this when you didn't clock in"). A guard who reports late doesn't get "already sent": their reason is added to the no-show ("Reported at 6:25 AM: …") and Operations is told, but it stays a no-show.

On the **employee record**, **Absences · last 90 days** lists both, "Reported" and "Not reported", with the count of each, so a pattern of no-shows stands out.

On **Clients**, the **Today** column shows **No clock-in** once a guard is past their grace period, **No-show · needs reliever** once it's been raised, and **Absent · needs reliever** for a reported absence nobody has covered yet. See [Clients: Today at the site](17-clients-and-regions.md#today-at-the-site-clientattendancetodayquery).

## Who can relieve (`ReliefCandidatesQuery`)

Active guards the viewer may see (a Satellite: only its region), except:

- the absent guard,
- anyone on **approved leave** that day,
- anyone who sent their **own** absence notice for that day,
- anyone **already relieving** someone, or **on duty**, that day,
- anyone **not rested**: still clocked in, or off a shift that ended less than **8 hours** before this duty starts (`reliever_min_rest_hours`). The night guard going home is no reliever for the day shift.

The list is ordered by how close they are: **same site, then the same province, then the same region, then everyone else**, by name. In the Assign dialog, each guard shows that as a badge, with a **tap-to-call** phone button (their contact number). For a no-show there's also a **Call the absent guard first** link. `AssignReliever` runs the same query again inside the transaction, so a guard who stopped being free can't be sent, and nobody is sent to two posts on one day. Days that have passed can't be assigned.

## Not covered: the post went without a guard

An absence still open after its day has passed becomes **Not covered** (`CloseUncoveredAbsences`, `app:close-uncovered-absences`, daily 00:15). Until 2026-10-17 such absences stayed "Needs reliever" forever, cluttering the list. Now they show a red **Not covered** badge and get their own tab on Relievers.

They're kept, never deleted, because that's what a client will ask about, and each is in the audit log (`absence.not_covered`). Today's open absences stay open: they can still be covered. The first run on the dev DB closed 19 old no-shows from Oct 14.

## The reliever's fare (Deployment Costs)

A reliever often pays to reach an unfamiliar post. Once a relief is done (its duty date today or earlier, within 60 days), its row shows **Claim the reliever's fare** for Operations (`file-deployment-claims`).

- The link opens **Deployment Costs** (`/deployment-claims?relief=`) with the form already filled: the reliever, *"Relief at Makati CBD · Roving, Oct 11, for Romeo Ocampo"* and the date. Operations adds the amount, purpose and receipt.
- Deployment Costs also lists **Reliefs to claim**: recent reliefs with no fare claimed, each with **Claim fare**, so none is forgotten.
- The claim is linked to the relief (`reimbursements.absence_notice_id`, migration `2026_10_17_100008`). Once claimed, the row shows *"Fare claimed ₱280.00 · Waiting for approval"*.
- **One standing claim per relief:** the relief row is locked while filing, and a second claim is refused (*"This relief's fare is already claimed"*). A rejected or cancelled claim can be filed again.

The rest is as for any deployment claim ([31](31-reimbursements.md#deployment-costs-roadmap-5)): the President or Vice President approves, and it's paid on the reliever's payslip, not taxed.

## Code

- `SubmitAbsenceNotice`, `CancelAbsenceNotice`, `AssignReliever`, `DetectNoShows` (+ command `app:detect-no-shows`), `RecordNoShowArrival`
- `ReliefCandidatesQuery`, `HoldoverQuery`
- `AbsenceNotice` (`source`, `duty_assignment_id`, `arrived_at`; `dutyWindow()`), `AbsenceNoticeStatus` (+ `arrived`), `AbsenceSource`
- `App\Livewire\Attendance\ReportAbsence` (guard), `App\Livewire\Admin\Relievers` (Operations, `manage-relievers`), `EmployeeDetail::recentAbsences`
- `AbsenceNoticeFiled`, `RelieverAssigned`, `NoShowAlert` (detected / escalated / arrived / arrived_covered)
- Config: `attendance.no_show` (`escalate_after_minutes`, `reliever_travel_minutes`, `reliever_min_rest_hours`)
- Audit area: **Operations** (`absence.filed`, `absence.covered`, `absence.cancelled`, `absence.no_show`, `absence.no_show_explained`, `absence.arrived`)

Tests: `tests/Feature/Operations/RelieversTest.php`. They cover: who is notified (HQ and the region's Satellite, not another region's), past days, the same day twice, office staff, assigning and both notices, leave and double-booking refused, Satellite limited to its region, and cancelling.

`tests/Feature/Operations/NoShowTest.php` covers:
- detection only after grace, and once, alerting HQ and the region's Satellite only;
- the guard still at the post, named in the alert;
- who is left alone: clocked in, reported, on leave, not on the roster, shift over;
- escalation once, 30 minutes on, and none once a reliever is sent;
- a late reliever's travel time;
- arriving late, with and without a reliever already sent;
- a late report adding its reason, and a no-show that can't be cancelled;
- reliever order and rest;
- the Relievers board with call links and the holdover;
- the employee record.

## Try it

1. As Maria (`employee@example.test`): Report Absence → a date this week → Notify Operations.
2. As `opshq@example.test`: Relievers → Assign reliever → pick a guard → Assign and notify.
3. Back as Maria: the notice says "Reliever assigned" with the name.
