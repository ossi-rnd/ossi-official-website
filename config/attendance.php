<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fallback shift
    |--------------------------------------------------------------------------
    |
    | Used when an employee has no shift assigned and no shift is marked as
    | the default. ShiftSeeder also creates a real row from these values.
    |
    */

    'default_shift' => [
        'name' => 'Day Shift',
        'start_time' => env('ATTENDANCE_DEFAULT_SHIFT_START', '09:00'),
        'end_time' => env('ATTENDANCE_DEFAULT_SHIFT_END', '18:00'),
        'grace_minutes' => (int) env('ATTENDANCE_DEFAULT_SHIFT_GRACE_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Punch photos
    |--------------------------------------------------------------------------
    |
    | Selfies are personal data under RA 10173, so they live on a private disk
    | and are only ever streamed through an authorized route. On Laravel Cloud
    | the local disk is wiped on every deploy: point this at an S3-compatible
    | bucket there.
    |
    */

    'photo_disk' => env('ATTENDANCE_PHOTO_DISK', 'local'),

    'photo_max_bytes' => 5 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Duty roster and anti-cheating rules
    |--------------------------------------------------------------------------
    |
    | Guards are rostered per day (Duty Roster). A clock-in must fall in its
    | duty's window: from `early_clock_in_minutes` before the start until the
    | end. Clocking in early is allowed but pays nothing extra: late, night
    | differential and overtime all count from the scheduled start, and
    | overtime is only paid when approved.
    |
    | A guard is "on the roster" once Operations has scheduled them anywhere
    | within `roster_window_days` of today; from then on a day without a duty
    | is a day off and clocking in is refused. Guards never rostered keep their
    | usual shift (the employee record), so nothing breaks before rosters exist.
    |
    | Punches must come from inside the client's geofence (its coordinates and
    | radius, set on Clients), with a GPS fix at least `max_gps_accuracy_meters`
    | accurate. A client without coordinates has no geofence yet.
    |
    */

    'early_clock_in_minutes' => (int) env('ATTENDANCE_EARLY_CLOCK_IN_MINUTES', 60),

    'roster_window_days' => 7,

    'max_gps_accuracy_meters' => (int) env('ATTENDANCE_MAX_GPS_ACCURACY_METERS', 100),

    'default_geofence_meters' => 150,

    /*
    |--------------------------------------------------------------------------
    | Patrols and time away from the post
    |--------------------------------------------------------------------------
    |
    | A roving duty must scan every checkpoint of its client once per round
    | (the client's patrol interval). A scan must be within
    | `checkpoint_radius_meters` of the checkpoint (or inside the client's
    | geofence when the checkpoint has no coordinates).
    |
    | Leaving the post is logged with a reason; past the limit it needs a
    | supervisor's approval, or Operations is alerted.
    |
    */

    'patrol' => [
        // A pinned checkpoint accepts scans this close to it (plus the phone's
        // GPS accuracy, capped): tight enough that the guardhouse is out of reach.
        'checkpoint_radius_meters' => 50,
        // The same checkpoint again within this many minutes is the same scan.
        'repeat_scan_minutes' => 5,
        // A round looks faked when its scans came faster than this per checkpoint
        // (nobody walks between spots in 20 seconds)...
        'suspicious_seconds_per_checkpoint' => 20,
        // ...or from one spot (all within this many metres of each other) while
        // the pinned checkpoints are at least `suspicious_spread_meters` apart.
        'suspicious_same_spot_meters' => 15,
        'suspicious_spread_meters' => 40,
    ],

    /*
    | A guard on the roster who hasn't clocked in when their grace period ends,
    | with no notice and no leave, is a no-show (DetectNoShows, every 5 minutes):
    | Operations is alerted and the post goes on the Relievers board. Still
    | uncovered this many minutes after the grace period, Operation HQ is
    | alerted again.
    */
    'no_show' => [
        'escalate_after_minutes' => (int) env('ATTENDANCE_NO_SHOW_ESCALATE_MINUTES', 30),
        // A reliever sent once the shift has begun can't be there by its grace period:
        // they count as a no-show only this long after being sent.
        'reliever_travel_minutes' => 60,
        // Not offered as a reliever: anyone whose own duty ended less than this long
        // before the one to cover starts (just off a night shift), or who is still on duty.
        'reliever_min_rest_hours' => 8,
    ],

    'away' => [
        // "How long" choices when stepping out, and "need more time" choices (minutes).
        'durations' => [15, 30, 60, 90, 120, 180, 240],
        'extensions' => [15, 30, 60, 120],
        // No absence is planned or extended past this many minutes in total.
        'max_minutes' => 480,
        // While away, a reminder on their phone to share their location this often.
        'location_update_minutes' => (int) env('ATTENDANCE_AWAY_LOCATION_MINUTES', 15),
        // The supervisor is alerted only after this long with no location shared.
        'location_alert_minutes' => (int) env('ATTENDANCE_AWAY_SILENT_MINUTES', 30),
        // ...or this long past the time they said they'd be back, without more time asked.
        'late_alert_minutes' => (int) env('ATTENDANCE_AWAY_LATE_MINUTES', 60),
        // An absence nobody ended (no "Back", no clock-out) is closed this long after the duty's end.
        'auto_close_after_minutes' => 60,
        // The GPS trail of an absence is deleted after this many days (Data Privacy Act:
        // no longer than needed). The reason, times and approval are kept with the attendance records.
        'location_retention_days' => (int) env('ATTENDANCE_AWAY_LOCATION_RETENTION_DAYS', 90),
    ],

    // Where office staff work: their "post" on the away map and the distance
    // they're shown at. Leave the coordinates empty to show no office.
    'office' => [
        'name' => env('OFFICE_NAME', 'Head office'),
        'latitude' => env('OFFICE_LATITUDE') !== null && env('OFFICE_LATITUDE') !== '' ? (float) env('OFFICE_LATITUDE') : null,
        'longitude' => env('OFFICE_LONGITUDE') !== null && env('OFFICE_LONGITUDE') !== '' ? (float) env('OFFICE_LONGITUDE') : null,
        'radius_meters' => 150,
    ],

    // Per user, per minute. The browser asks for an address while the camera is open.
    'reverse_geocode_throttle_per_minute' => (int) env('ATTENDANCE_REVERSE_GEOCODE_THROTTLE', 20),

];
