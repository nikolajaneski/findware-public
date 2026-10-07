<?php

namespace App\Enums;

enum ConsultationEventState: string
{
    case Active = 'active';
    case Unverified = 'unverified';
    case MeetPending = 'meet_pending';
    case MeetFailed = 'meet_failed';
    case Cancelled = 'cancelled';
}
