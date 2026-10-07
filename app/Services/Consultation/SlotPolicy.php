<?php

namespace App\Services\Consultation;

use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BusyWindow;
use Carbon\CarbonImmutable;

final class SlotPolicy
{
    public const DURATION_MINUTES = 20;

    public function eligible(BookingProfile $profile, CarbonImmutable $start, CarbonImmutable $now): bool
    {
        $local = $start->setTimezone($profile->timezone);
        $end = $start->addMinutes(self::DURATION_MINUTES)->setTimezone($profile->timezone);
        $today = $now->setTimezone($profile->timezone)->startOfDay();
        if ($start->second !== 0 || $start->micro !== 0 || $start->lessThan($now->addMinutes($profile->minimumLeadMinutes))
            || $local->lessThan($today) || $local->greaterThanOrEqualTo($today->addDays($profile->daysAhead + 1))
            || $local->toDateString() !== $end->toDateString() || in_array($local->toDateString(), $profile->excludedDates, true)) {
            return false;
        }

        $minute = $local->hour * 60 + $local->minute;
        $endMinute = $end->hour * 60 + $end->minute;
        foreach ($profile->weeklyHours[$local->isoWeekday()] ?? [] as [$open, $close]) {
            [$h, $m] = array_map('intval', explode(':', $open));
            [$eh, $em] = array_map('intval', explode(':', $close));
            $openMinute = $h * 60 + $m;
            if ($minute >= $openMinute && $endMinute >= $minute && $endMinute <= $eh * 60 + $em
                && ($minute - $openMinute) % self::DURATION_MINUTES === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<BusyWindow> $busy */
    public function free(BookingProfile $profile, CarbonImmutable $start, array $busy): bool
    {
        $from = $start->subMinutes($profile->bufferBeforeMinutes);
        $until = $start->addMinutes(self::DURATION_MINUTES + $profile->bufferAfterMinutes);
        foreach ($busy as $window) {
            if ($window->overlaps($from, $until)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<BusyWindow> $busy @return list<CarbonImmutable> */
    public function forDate(BookingProfile $profile, string $date, CarbonImmutable $now, array $busy): array
    {
        $from = CarbonImmutable::parse($date, $profile->timezone)->startOfDay()->utc();
        $until = $from->setTimezone($profile->timezone)->addDay()->startOfDay()->utc();
        $slots = [];
        // Iterate real instants: missing DST wall times never exist, repeated times retain distinct offsets.
        for ($cursor = $from; $cursor->lessThan($until); $cursor = $cursor->addMinute()) {
            if ($this->eligible($profile, $cursor, $now) && $this->free($profile, $cursor, $busy)) {
                $slots[] = $cursor;
            }
        }

        return $slots;
    }
}
