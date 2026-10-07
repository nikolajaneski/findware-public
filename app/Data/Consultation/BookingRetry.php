<?php

namespace App\Data\Consultation;

use Carbon\CarbonImmutable;

final readonly class BookingRetry
{
    public function __construct(
        public string $key,
        public string $eventId,
        public string $requestHash,
        public CarbonImmutable $start,
        public string $timezone,
        public string $token,
    ) {}

    public function profile(BookingProfile $current): BookingProfile
    {
        return new BookingProfile($current->calendarId, $this->timezone, $current->weeklyHours,
            $current->excludedDates, $current->daysAhead, $current->minimumLeadMinutes,
            $current->bufferBeforeMinutes, $current->bufferAfterMinutes);
    }
}
