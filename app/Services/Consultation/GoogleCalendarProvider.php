<?php

namespace App\Services\Consultation;

use App\Contracts\Consultation\CalendarProvider;
use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BusyWindow;
use App\Data\Consultation\CalendarEvent;
use App\Enums\ConsultationEventState;
use App\Exceptions\ConsultationProblem;
use App\Exceptions\ConsultationWriteUncertain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GoogleCalendarProvider implements CalendarProvider
{
    private ?string $accessToken = null;

    public function configured(bool $forWrite = true): bool
    {
        return config('consultation.google.calls_authorized') === true
            && (! $forWrite || config('consultation.google.writes_authorized') === true)
            && (! $forWrite || config('consultation.attendee_notifications_authorized') !== true)
            && is_string(config('consultation.google.client_id')) && config('consultation.google.client_id') !== ''
            && is_string(config('consultation.google.client_secret')) && config('consultation.google.client_secret') !== ''
            && is_string(config('consultation.google.refresh_token')) && config('consultation.google.refresh_token') !== '';
    }

    private function client(bool $forWrite = false): PendingRequest
    {
        if (! $this->configured($forWrite)) {
            throw ConsultationProblem::setup();
        }
        if ($this->accessToken === null) {
            try {
                $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)
                    ->post('https://oauth2.googleapis.com/token', [
                        'grant_type' => 'refresh_token',
                        'client_id' => config('consultation.google.client_id'),
                        'client_secret' => config('consultation.google.client_secret'),
                        'refresh_token' => config('consultation.google.refresh_token'),
                    ]);
            } catch (ConnectionException) {
                throw ConsultationProblem::provider();
            }
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw ConsultationProblem::provider();
            }
            // Request-scoped memory only. No persistent token cache or response logging.
            $this->accessToken = $token;
        }

        return Http::acceptJson()->withToken($this->accessToken)->connectTimeout(5)->timeout(15);
    }

    public function busy(BookingProfile $profile, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $response = $this->client()->post('https://www.googleapis.com/calendar/v3/freeBusy', [
                'timeMin' => $start->utc()->toIso8601String(),
                'timeMax' => $end->utc()->toIso8601String(),
                'timeZone' => $profile->timezone,
                'items' => [['id' => $profile->calendarId]],
            ]);
        } catch (ConnectionException) {
            throw ConsultationProblem::provider();
        }
        $calendars = $response->json('calendars');
        $calendar = is_array($calendars) ? ($calendars[$profile->calendarId] ?? null) : null;
        if (! $response->successful() || ! is_array($calendar) || ! array_key_exists('busy', $calendar)
            || ! is_array($calendar['busy']) || ! empty($calendar['errors'])) {
            throw ConsultationProblem::provider();
        }
        $result = [];
        foreach ($calendar['busy'] as $window) {
            try {
                if (! is_array($window) || ! is_string($window['start'] ?? null) || ! is_string($window['end'] ?? null)
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $window['start'])
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $window['end'])) {
                    throw ConsultationProblem::provider();
                }
                $from = CarbonImmutable::parse($window['start'])->utc();
                $until = CarbonImmutable::parse($window['end'])->utc();
                if (! $from->lessThan($until)) {
                    throw ConsultationProblem::provider();
                }
                $result[] = new BusyWindow($from, $until);
            } catch (Throwable) {
                throw ConsultationProblem::provider();
            }
        }

        return $result;
    }

    public function create(BookingProfile $profile, string $eventId, BookingDetails $details): CalendarEvent
    {
        // Refresh failures occur before a write can have started.
        $client = $this->client(true);
        $payload = [
            'id' => $eventId,
            'summary' => 'Free 20-minute consultation',
            'description' => 'Name: '.htmlspecialchars($details->name, ENT_QUOTES, 'UTF-8')."\nEmail: ".htmlspecialchars($details->email, ENT_QUOTES, 'UTF-8')."\nCampaign brief: ".htmlspecialchars($details->brief, ENT_QUOTES, 'UTF-8'),
            'start' => ['dateTime' => $details->start->utc()->toIso8601String(), 'timeZone' => $profile->timezone],
            'end' => ['dateTime' => $details->start->addMinutes(20)->utc()->toIso8601String(), 'timeZone' => $profile->timezone],
            'extendedProperties' => ['private' => ['findwardRequestHash' => $details->fingerprint()]],
            'guestsCanInviteOthers' => false,
            'guestsCanModify' => false,
            'guestsCanSeeOtherGuests' => false,
        ];
        $payload['conferenceData'] = ['createRequest' => ['requestId' => $eventId, 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        try {
            // Organizer calendar only. This simplified path cannot send attendee invitations.
            $response = $client->post($this->eventUrl($profile).'?sendUpdates=none&conferenceDataVersion=1', $payload);
        } catch (ConnectionException) {
            throw new ConsultationWriteUncertain;
        }
        if ($response->status() === 409) {
            try {
                return $this->find($profile, $eventId) ?? throw new ConsultationWriteUncertain;
            } catch (ConsultationProblem) {
                throw new ConsultationWriteUncertain;
            }
        }
        if ($response->serverError() || $response->status() === 429 || $response->status() === 408) {
            throw new ConsultationWriteUncertain;
        }
        if (! $response->successful()) {
            // A definite provider rejection, never expose its body or credentials.
            throw new ConsultationProblem('booking_rejected', 503, 'The consultation could not be booked. Please try again later.');
        }

        return $this->decodeEvent($response, $profile);
    }

    public function find(BookingProfile $profile, string $eventId): ?CalendarEvent
    {
        try {
            $response = $this->client()->get($this->eventUrl($profile).'/'.$eventId);
        } catch (ConnectionException) {
            throw ConsultationProblem::provider();
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw ConsultationProblem::provider();
        }

        return $this->decodeEvent($response, $profile);
    }

    private function eventUrl(BookingProfile $profile): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($profile->calendarId).'/events';
    }

    private function decodeEvent(Response $response, BookingProfile $profile): CalendarEvent
    {
        $id = $response->json('id');
        if (! is_string($id)) {
            throw new ConsultationWriteUncertain;
        }
        // Deleted events may be tombstones with only id/status. They are positive cancellation evidence.
        if ($response->json('status') === 'cancelled') {
            return new CalendarEvent($id, null, null, false, null, ConsultationEventState::Cancelled);
        }
        $start = $response->json('start.dateTime');
        $end = $response->json('end.dateTime');
        if (! is_string($start) || ! is_string($end)) {
            throw new ConsultationWriteUncertain;
        }
        $meeting = null;
        $points = $response->json('conferenceData.entryPoints', []);
        if (! is_array($points)) {
            throw new ConsultationWriteUncertain;
        }
        foreach ($points as $point) {
            if (is_array($point) && ($point['entryPointType'] ?? null) === 'video' && is_string($point['uri'] ?? null)
                && filter_var($point['uri'], FILTER_VALIDATE_URL) && parse_url($point['uri'], PHP_URL_SCHEME) === 'https') {
                $meeting = $point['uri'];
            }
        }
        try {
            $state = $response->json('status') === 'confirmed' ? ConsultationEventState::Active : ConsultationEventState::Unverified;
            $conferenceStatus = $response->json('conferenceData.createRequest.status.statusCode');
            if ($conferenceStatus === 'failure') {
                $state = ConsultationEventState::MeetFailed;
            } elseif ($conferenceStatus === 'pending' || $meeting === null) {
                $state = ConsultationEventState::MeetPending;
            }

            $requestHash = $response->json('extendedProperties.private.findwardRequestHash');

            return new CalendarEvent($id, CarbonImmutable::parse($start)->utc(), CarbonImmutable::parse($end)->utc(), $state === ConsultationEventState::Active, $meeting, $state,
                is_string($requestHash) ? $requestHash : null);
        } catch (Throwable) {
            throw new ConsultationWriteUncertain;
        }
    }
}
