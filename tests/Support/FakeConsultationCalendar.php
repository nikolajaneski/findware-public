<?php

namespace Tests\Support;

use App\Contracts\Consultation\CalendarProvider;
use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BusyWindow;
use App\Data\Consultation\CalendarEvent;
use App\Exceptions\ConsultationProblem;
use App\Exceptions\ConsultationWriteUncertain;
use Carbon\CarbonImmutable;
use Closure;

final class FakeConsultationCalendar implements CalendarProvider
{
    public bool $ready = true;
    public int $creates = 0;
    public int $reads = 0;
    public int $finds = 0;
    public bool $uncertain = false;
    public bool $persistBeforeFailure = false;
    public ?Closure $onCreate = null;
    public ?ConsultationProblem $findProblem = null;
    /** @var list<BusyWindow> */
    public array $busyWindows = [];
    /** @var array<string, CalendarEvent> */
    public array $events = [];

    public function configured(bool $forWrite = true): bool
    {
        return $this->ready;
    }

    public function busy(BookingProfile $profile, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $this->reads++;

        return $this->busyWindows;
    }

    public function create(BookingProfile $profile, string $eventId, BookingDetails $details): CalendarEvent
    {
        $this->creates++;
        if ($this->onCreate) {
            ($this->onCreate)();
        }
        $event = new CalendarEvent($eventId, $details->start, $details->start->addMinutes(20), true, 'https://meet.google.com/fixture',
            requestHash: $details->fingerprint());
        if (! $this->uncertain || $this->persistBeforeFailure) {
            $this->events[$eventId] = $event;
            $this->busyWindows[] = new BusyWindow($details->start, $details->start->addMinutes(20));
        }
        if ($this->uncertain) {
            throw new ConsultationWriteUncertain;
        }

        return $event;
    }

    public function find(BookingProfile $profile, string $eventId): ?CalendarEvent
    {
        $this->finds++;
        if ($this->findProblem) {
            throw $this->findProblem;
        }
        return $this->events[$eventId] ?? null;
    }
}
