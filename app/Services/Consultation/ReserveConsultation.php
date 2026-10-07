<?php

namespace App\Services\Consultation;

use App\Contracts\Consultation\CalendarProvider;
use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BookingRetry;
use App\Data\Consultation\CalendarEvent;
use App\Enums\ConsultationEventState;
use App\Exceptions\ConsultationProblem;
use App\Exceptions\ConsultationWriteUncertain;
use Carbon\CarbonImmutable;
use Illuminate\Session\Store;

final class ReserveConsultation
{
    public function __construct(
        private CalendarProvider $provider,
        private SlotPolicy $policy,
        private SignedBookingReference $references,
        private CalendarMutex $mutex,
    ) {}

    /** @return array<string, mixed> */
    public function reserve(BookingProfile $profile, BookingDetails $details, string $key, Store $session, ?string $token = null): array
    {
        return $this->mutex->run($profile->calendarHash(), function () use ($profile, $details, $key, $session, $token): array {
            $entry = $session->get('consultation.requests.'.$key);
            if ($token !== null || is_array($entry)) {
                $retry = $this->references->verify($token ?? $entry['reference'], $profile, $key, $session->getId());
                if (! hash_equals($retry->requestHash, $details->fingerprint())) {
                    throw new ConsultationProblem('idempotency_mismatch', 409, 'This request reference belongs to different booking details.');
                }
                if (is_string($entry['rejected'] ?? null)) {
                    throw new ConsultationProblem($entry['rejected'], 409, 'This booking was not completed. Please choose another time.');
                }
                // A supplied reference is a recovery request. Never infer permission to insert from a 404.
                if ($token !== null || ($entry['attempted'] ?? false)) {
                    return $this->recover($profile, $retry);
                }
            } else {
                if (count($session->get('consultation.requests', [])) >= 20) {
                    throw new ConsultationProblem('rate_limited', 429, 'Please contact us by email to arrange your consultation.');
                }
                if (! $this->policy->eligible($profile, $details->start, CarbonImmutable::now('UTC'))) {
                    throw ConsultationProblem::conflict();
                }
                $retry = $this->references->issue($profile, $details, $key, $session->getId());
                $entry = ['reference' => $retry->token, 'attempted' => false, 'rejected' => null];
                $this->checkpoint($session, $key, $entry);
            }

            try {
                // An existing deterministic identity is always read before a fresh insertion.
                $existing = $this->provider->find($retry->profile($profile), $retry->eventId);
                if ($existing !== null) {
                    $entry['attempted'] = true;
                    $this->checkpoint($session, $key, $entry);

                    return $this->result($retry, $existing);
                }
                if (! $this->policy->eligible($profile, $details->start, CarbonImmutable::now('UTC'))) {
                    throw ConsultationProblem::conflict();
                }
                $busy = $this->provider->busy($profile, $details->start->subMinutes($profile->bufferBeforeMinutes),
                    $details->start->addMinutes(20 + $profile->bufferAfterMinutes));
                if (! $this->policy->free($profile, $details->start, $busy)
                    || ! $this->policy->eligible($profile, $details->start, CarbonImmutable::now('UTC'))) {
                    throw ConsultationProblem::conflict();
                }
            } catch (ConsultationProblem $problem) {
                $entry['rejected'] = $problem->problem;
                $this->checkpoint($session, $key, $entry);
                throw $problem;
            } catch (ConsultationWriteUncertain) {
                $entry['attempted'] = true;
                $this->checkpoint($session, $key, $entry);

                return $this->result($retry, null, 'event_unverified');
            }

            // Save and verify the technical marker BEFORE any write. No attendee data is persisted.
            $entry['attempted'] = true;
            $this->checkpoint($session, $key, $entry);
            try {
                return $this->result($retry, $this->provider->create($retry->profile($profile), $retry->eventId, $details));
            } catch (ConsultationWriteUncertain) {
                return $this->recover($profile, $retry);
            } catch (ConsultationProblem $problem) {
                // A definite provider rejection cannot authorize another insert for this reference.
                $entry['rejected'] = $problem->problem;
                $this->checkpoint($session, $key, $entry);
                throw $problem;
            }
        });
    }

    /** @return array<string, mixed> */
    public function status(BookingProfile $profile, string $key, Store $session, ?string $token = null): array
    {
        $entry = $session->get('consultation.requests.'.$key);
        $reference = $token ?? (is_array($entry) ? ($entry['reference'] ?? null) : null);
        abort_unless(is_string($reference), 404);
        $retry = $this->references->verify($reference, $profile, $key, $session->getId());
        if (is_string($entry['rejected'] ?? null)) {
            throw new ConsultationProblem($entry['rejected'], 409, 'This booking was not completed. Please choose another time.');
        }

        return $this->recover($profile, $retry);
    }

    /** @param array{reference: string, attempted: bool, rejected: ?string} $entry */
    private function checkpoint(Store $session, string $key, array $entry): void
    {
        $session->put('consultation.requests.'.$key, $entry);
        $session->save();
        // The isolated public store uses JSON file sessions. Failed persistence must stop the write.
        $raw = $session->getHandler()->read($session->getId());
        $saved = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($saved) || ($saved['consultation']['requests'][$key] ?? null) !== $entry) {
            throw ConsultationProblem::setup();
        }
    }

    /** @return array<string, mixed> */
    private function recover(BookingProfile $profile, BookingRetry $retry): array
    {
        try {
            return $this->result($retry, $this->provider->find($retry->profile($profile), $retry->eventId));
        } catch (ConsultationProblem $problem) {
            if ($problem->problem === 'event_cancelled') {
                throw $problem;
            }

            return $this->result($retry, null, 'calendar_unavailable');
        } catch (ConsultationWriteUncertain) {
            return $this->result($retry, null, 'event_unverified');
        }
    }

    /** @return array<string, mixed> */
    private function result(BookingRetry $retry, ?CalendarEvent $event, string $reason = 'missing_event'): array
    {
        $confirmed = false;
        if ($event !== null) {
            if (! hash_equals($retry->eventId, $event->id)) {
                $reason = 'event_unverified';
            } elseif ($event->state === ConsultationEventState::Cancelled) {
                throw new ConsultationProblem('event_cancelled', 409, 'This calendar booking has been cancelled. Please choose another time.');
            } elseif ($event->start === null || $event->end === null || ! $event->start->equalTo($retry->start)
                || ! $event->end->equalTo($retry->start->addMinutes(20))
                || $event->requestHash === null || ! hash_equals($retry->requestHash, $event->requestHash)) {
                $reason = 'event_unverified';
            } elseif ($event->confirmed && $event->state === ConsultationEventState::Active && $event->meetingUrl !== null) {
                $confirmed = true;
            } else {
                $reason = $event->state->value;
            }
        }

        return ['reference' => $retry->key, 'retry_reference' => $retry->token, 'status' => $confirmed ? 'confirmed' : 'uncertain',
            'recovery_state' => $confirmed ? null : $reason, 'start' => $retry->start->toIso8601String(),
            'end' => $retry->start->addMinutes(20)->toIso8601String(), 'timezone' => $retry->timezone,
            'duration_minutes' => 20, 'meeting_url' => $confirmed ? $event->meetingUrl : null];
    }
}
