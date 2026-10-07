<?php

namespace App\Services\Consultation;

use App\Contracts\Consultation\CalendarProvider;
use App\Data\Consultation\BookingProfile;
use App\Exceptions\ConsultationProblem;
use Carbon\CarbonImmutable;

final class BookingAvailability
{
    public function __construct(private CalendarProvider $provider, private SlotPolicy $policy) {}

    /** @return array<string, mixed> */
    public function forDate(BookingProfile $profile, string $date): array
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($profile->timezone)->startOfDay();
        $day = CarbonImmutable::parse($date, $profile->timezone)->startOfDay();
        if ($day->lessThan($today) || $day->greaterThan($today->addDays($profile->daysAhead))) {
            throw new ConsultationProblem('date_out_of_range', 422, 'Please choose a date within the booking window.');
        }
        $from = $day->utc()->subMinutes($profile->bufferBeforeMinutes);
        $until = $day->addDay()->utc()->addMinutes($profile->bufferAfterMinutes);
        $busy = $this->provider->busy($profile, $from, $until);
        $slots = $this->policy->forDate($profile, $date, $now, $busy);

        return ['date' => $date, 'timezone' => $profile->timezone, 'duration_minutes' => 20, 'slots' => array_map(fn (CarbonImmutable $s): array => [
            'start' => $s->toIso8601String(), 'end' => $s->addMinutes(20)->toIso8601String(),
            'label' => $s->setTimezone($profile->timezone)->format('H:i P'),
        ], $slots)];
    }
}
