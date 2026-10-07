<?php

namespace App\Data\Consultation;

use App\Enums\ConsultationEventState;
use Carbon\CarbonImmutable;

final readonly class CalendarEvent
{
    public function __construct(
        public string $id,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public bool $confirmed,
        public ?string $meetingUrl,
        public ConsultationEventState $state = ConsultationEventState::Active,
        public ?string $requestHash = null,
    ) {}
}
