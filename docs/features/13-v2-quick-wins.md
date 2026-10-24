# 13. V2 phase 2.0: quick wins

Phase 2.0 of the [V2 roadmap](12-version-2-roadmap.md). It covers seven items plus the notification foundation that later phases build on.

| Item | What you see | Where it lives |
| --- | --- | --- |
| 28 Filed date | A "Filed" column (date + time) on My Leave, My Overtime, Leave Requests and Overtime Requests | `components/filed-at.blade.php` |
| 12 Full late details | "Late (N min, D days) · Show days" on every payslip: date, shift start + grace, time in, minutes late | `Queries/Payroll/LateDaysQuery`, `Data/LateDay`, `components/payslip-breakdown` |
| 10 Disclaimer | "Subject to change with prior notice…" under every payslip | `config('payroll.disclaimer')` |
| 2 Screenshot deterrents | Viewer watermark, print blocked, content covered when the window loses focus, payslip views audited | `components/sensitive.blade.php`, `Actions/Payroll/RecordPayslipView` |
| Foundation | Bell in the top bar, `notifications` table, scheduler | `Livewire/NotificationBell`, `routes/console.php` |
| 15 Probation alert | HR and the head get a bell item and an email 30 days before and on the last day. Since 2026-10-24 the head evaluates and HR approves; with no approved decision, the daily check makes them Regular (Art. 296) instead of reminding weekly. See [35](35-probation-and-employment-status.md) | `Actions/People/SendProbationAlerts`, `ApplyProbationEnds`, `Support/ProbationRules` |
| 39 Announcements | Admin, HR and Super Admin publish advisories; a banner for the audience, the bell, and email if urgent | `Livewire/Admin/Announcements`, `Actions/Content/*`, `components/announcement-banner` |
| 35 FAQs | "Help & FAQs" for everyone; editors add, edit and hide questions in place | `Livewire/Help`, `Actions/Content/SaveFaq` |

## One new gate

`Ability::PublishContent` (Admin, HR, Super Admin) covers announcements and FAQ edits. It is added to `Ability::roles()` like the other gates, so the route, the sidebar and `RouteAccessTest` pick it up automatically.

## Notifications: why no polling

The bell never uses `wire:poll`, because every request resets the idle timeout (`EnforceIdleTimeout`). A poll every minute would keep an unattended payroll screen signed in forever. Here, one security control (auto logout) shapes the design of another feature.

**Live updates** come over a WebSocket instead (Laravel Reverb):

- `Listeners/BroadcastNewNotification` hears every `NotificationSent` on the `database` channel and broadcasts `Events/NotificationArrived` to `private-App.Models.User.{id}` (only that person can join it: `routes/channels.php`). One listener, so every notification class, present and future, is live without changes.
- `resources/js/live-bell.js` draws it **in the browser**: the badge count, the new item at the top of the list, and a pop-up for 8 seconds. **No request is made**, so the idle timeout is untouched. Clicking it is real activity and goes through Livewire as usual.
- Joining the channel (`/broadcasting/auth`) is not counted as activity either: an unattended tab re-joins by itself whenever its connection drops.
- If Reverb is down or not configured, nothing breaks: the notification is still saved (a broadcast failure is reported, never retried, so nothing is sent twice) and the bell shows it on the next page load.

**What reaches the bell.** To the person it's about:
- the answer to their **leave** or **overtime** (`RequestDecided`, bell and email, with the reviewer's note, and "you asked for 3 hours" when less overtime was approved);
- their **payslip** once the run is approved, and again when it's paid (`PayslipReady`, bell only, so a whole payroll doesn't send hundreds of emails);
- load requests, complaints, returned 201 documents, relief duties.

To whoever must act:
- requests waiting for them, no-shows and their escalation;
- missed or faked patrol rounds;
- someone **60+ minutes past** the time they said they'd be back, or silent for **30+ minutes**;
- time away **never ended** (closed at the duty's end, `CloseForgottenAways` → `PatrolAlert`);
- probation, 201 and license due dates, daily rate needed, payroll adjustments awaiting approval.

The time-based alerts (no-shows, late time away, document expiry…) come from the **scheduler**: without it, those are never raised. Notices caused by someone's action (a leave filed, a request decided) reach the bell at once, even with no worker; only their **email** waits for the queue worker.

Locally, `composer dev` starts Reverb (and the scheduler) with the server, queue worker and Vite. Your `.env` needs the Reverb lines from `.env.example`. Tests: `tests/Feature/LiveNotificationsTest.php`.

Every notification class implements `ShouldQueue` and uses `App\Support\WritesBellImmediately` (arch tests enforce both): `viaConnections()` sends the `database` channel on the `sync` connection, so the bell row and its live push happen in the request, and only `mail` goes to the `jobs` table. A Reverb failure can't break the request: `BroadcastNewNotification` reports and swallows it.

## Scheduled checks are idempotent

`sent_alerts` has a unique index on `(alert, subject_type, subject_id)`. `SentAlert::claim()` uses `createOrFirst`, so only the first run, even with two running at once, gets `true` and sends anything. The claim, the notification and the audit entry share one transaction: if queueing fails, the claim rolls back and the next run tries again.

```php
$wasSent = DB::transaction(function () use (...) {
    if (! SentAlert::claim($alert, $profile)) {
        return false;            // already sent: a rerun is harmless
    }
    Notification::send($recipients, new ProbationEnding(...));
    $this->audit->record(...);
    return true;
});
```

Recipients come from `User::notifiable($roles)`: role holders who still work here. HR staff who have resigned are skipped.

## Late details always add up

`LateDaysQuery` reads the same `time_logs.late_minutes` over the same run dates that `PayslipInputsQuery` sums for the payslip. The list therefore always explains the total, and the deduction is exactly that total at the hourly rate (the brackets were removed on 2026-10-23).

## Screenshot deterrents, honestly

A web page cannot block a phone's screenshot button or a second camera. `<x-sensitive>` makes leaks **traceable** and casual copying **harder**:

1. A tiled SVG watermark with the viewer's name, employee number and time. It is generated server-side and escaped with `e()`.
2. `@media print` hides the content and prints a notice instead.
3. On window `blur` or `visibilitychange`, an overlay covers the content. The overlay uses `backdrop-blur`. A `filter` on the wrapper itself would break the `position: fixed` of any modal inside it.
4. `payslip.viewed` audit entries record who opened whose payslip.

## Announcements

- `audience` is a JSON list of role values; `null` means everyone. The banner filters it in PHP, because only a handful are live at once and JSON queries differ between SQLite and MySQL.
- Saving a live announcement notifies its audience straight away. One with a future start is notified by `app:publish-announcements` (every 5 minutes) once it starts. The `sent_alerts` claim makes the two paths safe to overlap.
- Urgent announcements go to the bell and to email. Routine ones go to the bell only, so inboxes stay quiet.
- Dismissing a banner is stored in `localStorage`, per browser. It is a convenience only and nothing depends on it.

## FAQs

Answers render with `nl2br(e($answer))`: HR's line breaks are kept, and anything typed as HTML is shown as text (there is a test for it). Drafts are visible only to editors.

## Try it

```bash
php artisan migrate:fresh --seed
php artisan app:send-probation-alerts      # demo data has 4 probationary guards near or past 6 months
php artisan queue:work --stop-when-empty   # delivers them; see the note below about mail
```

Sign in as `hr@example.test`: the bell shows the alerts and the sidebar has Announcements. Sign in as `employee@example.test` and open a payslip, then **Show days**.

> **Local mail:** `.env` sends through Gmail. Demo users have `@example.test` addresses, so run the worker with the log mailer locally (`MAIL_MAILER=log php artisan queue:work`), or set `MAIL_MAILER=log` in `.env` while testing. That keeps Gmail from bouncing mail to fake addresses.

## Tests

- `tests/Feature/Content/AnnouncementsAndHelpTest.php`: audience targeting, notify-once, scheduled start, urgent mail channel, banner visibility, gate, bell links, FAQ drafts and XSS escaping.
- `tests/Feature/People/ProbationAlertsTest.php`: 30-day and due-day alerts, once each, resigned HR skipped, non-candidates ignored. Also: past probation with no decision → Regular by law (once), the "ended 3 days ago" wording, and the overdue marks on the list and dashboard. The evaluation flow is in `ProbationEvaluationTest.php`.
- `tests/Feature/Payroll/PayrollTest.php`: late-day list and disclaimer, payslip-view audit, watermark.
