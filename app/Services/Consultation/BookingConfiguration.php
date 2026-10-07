<?php

namespace App\Services\Consultation;

use App\Contracts\Consultation\CalendarProvider;
use App\Data\Consultation\BookingProfile;
use App\Exceptions\ConsultationProblem;
use DateTimeZone;

final class BookingConfiguration
{
    public function __construct(private CalendarProvider $provider) {}

    public function require(bool $forRecovery = false): BookingProfile
    {
        $c = config('consultation');
        if (! is_array($c) || (! $forRecovery && ! ($c['enabled'] ?? false)) || ! $this->provider->configured(! $forRecovery)) {
            throw ConsultationProblem::setup();
        }

        $zone = $c['timezone'] ?? null;
        $hours = $c['weekly_hours'] ?? null;
        if (! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(), true)
            || ! is_string($c['calendar_id'] ?? null) || trim($c['calendar_id']) === '' || trim($c['calendar_id']) !== $c['calendar_id']
            || ! is_array($hours) || $hours === []) {
            throw ConsultationProblem::setup();
        }

        foreach ($hours as $day => $windows) {
            if (! in_array((string) $day, ['1', '2', '3', '4', '5', '6', '7'], true) || ! is_array($windows) || $windows === []) {
                throw ConsultationProblem::setup();
            }
            foreach ($windows as $window) {
                if (! is_array($window) || count($window) !== 2 || ! isset($window[0], $window[1])
                    || ! is_string($window[0]) || ! is_string($window[1])
                    || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $window[0])
                    || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $window[1]) || $window[0] >= $window[1]) {
                    throw ConsultationProblem::setup();
                }
            }
        }

        $excluded = $c['excluded_dates'] ?? [];
        if (! is_array($excluded) || array_filter($excluded, fn ($d): bool => ! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))) {
            throw ConsultationProblem::setup();
        }
        foreach (['days_ahead' => [1, 60], 'minimum_lead_minutes' => [0, 10080], 'buffer_before_minutes' => [0, 120], 'buffer_after_minutes' => [0, 120]] as $key => [$min, $max]) {
            if (! is_int($c[$key] ?? null) || $c[$key] < $min || $c[$key] > $max) {
                throw ConsultationProblem::setup();
            }
        }

        if (! ($c['google_meet_supported'] ?? false)) {
            throw ConsultationProblem::setup();
        }
        return new BookingProfile($c['calendar_id'], $zone, $hours, array_values($excluded), $c['days_ahead'], $c['minimum_lead_minutes'], $c['buffer_before_minutes'], $c['buffer_after_minutes']);
    }
}
