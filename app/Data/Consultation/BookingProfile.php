<?php

namespace App\Data\Consultation;

final readonly class BookingProfile
{
    /** @param array<int, list<array{0: string, 1: string}>> $weeklyHours @param list<string> $excludedDates */
    public function __construct(
        public string $calendarId,
        public string $timezone,
        public array $weeklyHours,
        public array $excludedDates,
        public int $daysAhead,
        public int $minimumLeadMinutes,
        public int $bufferBeforeMinutes,
        public int $bufferAfterMinutes,
    ) {
        if (trim($calendarId) === '' || $calendarId !== trim($calendarId)) {
            throw new \InvalidArgumentException('An explicit owner calendar is required.');
        }
    }

    public function calendarHash(): string
    {
        return hash('sha256', $this->calendarId);
    }
}
