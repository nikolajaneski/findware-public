<?php

return [
    // Enabling the feature does not authorize a calendar connection or writes.
    'enabled' => env('CONSULTATION_BOOKING_ENABLED', false) === true,
    // Owner-confirmed API calendar. Public consultations do not belong to a client workspace.
    'calendar_id' => env('CONSULTATION_CALENDAR_ID', 'nikola.janeski@gmail.com'),
    'timezone' => env('CONSULTATION_TIMEZONE', 'Europe/Skopje'),
    // Confirmed Monday-Friday 09:00-17:00. ISO weekday keys; explicit empty/invalid overrides fail closed.
    'weekly_hours' => json_decode((string) env('CONSULTATION_WEEKLY_HOURS', '{"1":[["09:00","17:00"]],"2":[["09:00","17:00"]],"3":[["09:00","17:00"]],"4":[["09:00","17:00"]],"5":[["09:00","17:00"]]}'), true),
    'excluded_dates' => json_decode((string) env('CONSULTATION_EXCLUDED_DATES', '[]'), true),
    'days_ahead' => filter_var(env('CONSULTATION_DAYS_AHEAD', 30), FILTER_VALIDATE_INT),
    'minimum_lead_minutes' => filter_var(env('CONSULTATION_MINIMUM_LEAD_MINUTES', 60), FILTER_VALIDATE_INT),
    'buffer_before_minutes' => filter_var(env('CONSULTATION_BUFFER_BEFORE_MINUTES', 0), FILTER_VALIDATE_INT),
    'buffer_after_minutes' => filter_var(env('CONSULTATION_BUFFER_AFTER_MINUTES', 0), FILTER_VALIDATE_INT),
    // Hosted page uses Meet; the selected API calendar's conference capability still needs verification.
    'google_meet_supported' => env('CONSULTATION_GOOGLE_MEET_SUPPORTED', false) === true,
    'attendee_notifications_authorized' => env('CONSULTATION_ATTENDEE_NOTIFICATIONS_AUTHORIZED', false) === true,
    // Separate public file sessions and one calendar lock; no SQL/cache database requirement.
    'runtime_path' => storage_path('framework/consultation'),
    'google' => [
        'calls_authorized' => env('CONSULTATION_GOOGLE_CALLS_AUTHORIZED', false) === true,
        'writes_authorized' => env('CONSULTATION_GOOGLE_WRITES_AUTHORIZED', false) === true,
        'client_id' => env('CONSULTATION_GOOGLE_CLIENT_ID'),
        'client_secret' => env('CONSULTATION_GOOGLE_CLIENT_SECRET'),
        'refresh_token' => env('CONSULTATION_GOOGLE_REFRESH_TOKEN'),
    ],
];
