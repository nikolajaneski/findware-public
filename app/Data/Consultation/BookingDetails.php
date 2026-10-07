<?php

namespace App\Data\Consultation;

use Carbon\CarbonImmutable;

final readonly class BookingDetails
{
    public function __construct(public string $name, public string $email, public string $brief, public CarbonImmutable $start) {}

    public function fingerprint(): string
    {
        return hash_hmac('sha256', json_encode([$this->name, $this->email, $this->brief, $this->start->utc()->toIso8601String()], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
