<?php

namespace App\Contracts\Consultation;

use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BusyWindow;
use App\Data\Consultation\CalendarEvent;
use Carbon\CarbonImmutable;

interface CalendarProvider
{
    public function configured(bool $forWrite = true): bool;

    /** @return list<BusyWindow> */
    public function busy(BookingProfile $profile, CarbonImmutable $start, CarbonImmutable $end): array;

    public function create(BookingProfile $profile, string $eventId, BookingDetails $details): CalendarEvent;

    public function find(BookingProfile $profile, string $eventId): ?CalendarEvent;
}
