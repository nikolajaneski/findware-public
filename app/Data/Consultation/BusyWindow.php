<?php

namespace App\Data\Consultation;

use Carbon\CarbonImmutable;

final readonly class BusyWindow
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public function overlaps(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->start->lessThan($end) && $this->end->greaterThan($start);
    }
}
