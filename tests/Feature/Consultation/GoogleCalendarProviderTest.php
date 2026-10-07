<?php

namespace Tests\Feature\Consultation;

use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\BookingProfile;
use App\Enums\ConsultationEventState;
use App\Exceptions\ConsultationProblem;
use App\Exceptions\ConsultationWriteUncertain;
use App\Services\Consultation\GoogleCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GoogleCalendarProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        config(['consultation.google.calls_authorized' => true, 'consultation.google.writes_authorized' => true,
            'consultation.google.client_id' => 'fixture-client', 'consultation.google.client_secret' => 'fixture-secret',
            'consultation.google.refresh_token' => 'fixture-refresh', 'consultation.attendee_notifications_authorized' => false]);
    }

    private function profile(): BookingProfile
    {
        return new BookingProfile('fixture@example.test', 'Europe/Skopje', [1 => [['09:00', '17:00']]], [], 30, 60, 0, 0);
    }

    private function details(): BookingDetails
    {
        return new BookingDetails('Fixture Attendee', 'attendee@example.test', '<b>Fixture</b>', CarbonImmutable::parse('2026-10-06T07:00:00Z'));
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        return ['id' => str_repeat('a', 64), 'status' => 'confirmed',
            'start' => ['dateTime' => '2026-10-06T07:00:00Z'], 'end' => ['dateTime' => '2026-10-06T07:20:00Z'],
            'extendedProperties' => ['private' => ['findwardRequestHash' => $this->details()->fingerprint()]],
            'conferenceData' => ['createRequest' => ['status' => ['statusCode' => 'success']],
                'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/fixture']]]];
    }

    public function test_disabled_calls_never_contact_google(): void
    {
        config(['consultation.google.calls_authorized' => false]);
        try {
            (new GoogleCalendarProvider)->busy($this->profile(), CarbonImmutable::now(), CarbonImmutable::now()->addDay());
            $this->fail('Disabled calls must fail closed.');
        } catch (ConsultationProblem $problem) {
            $this->assertSame('setup_required', $problem->problem);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_writes_cannot_create_an_event(): void
    {
        config(['consultation.google.writes_authorized' => false]);
        $this->expectException(ConsultationProblem::class);
        try {
            (new GoogleCalendarProvider)->create($this->profile(), str_repeat('a', 64), $this->details());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unsupported_invitation_activation_fails_closed_without_sql_or_google(): void
    {
        config(['consultation.attendee_notifications_authorized' => true]);
        $this->assertFalse((new GoogleCalendarProvider)->configured());
        $this->expectException(ConsultationProblem::class);
        try {
            (new GoogleCalendarProvider)->create($this->profile(), str_repeat('a', 64), $this->details());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_missing_or_failed_busy_calendar_never_fabricates_availability(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']),
            'www.googleapis.com/*' => Http::response(['calendars' => ['fixture@example.test' => ['errors' => [['reason' => 'notFound']]]]])]);
        $this->expectException(ConsultationProblem::class);
        (new GoogleCalendarProvider)->busy($this->profile(), CarbonImmutable::now(), CarbonImmutable::now()->addDay());
    }

    public function test_event_identity_payload_fingerprint_twenty_minutes_and_no_notifications(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']), 'www.googleapis.com/*' => Http::response($this->event())]);
        $result = (new GoogleCalendarProvider)->create($this->profile(), str_repeat('a', 64), $this->details());
        $this->assertTrue($result->confirmed);
        $this->assertSame('https://meet.google.com/fixture', $result->meetingUrl);
        $this->assertSame($this->details()->fingerprint(), $result->requestHash);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sendUpdates=none') && $r['id'] === str_repeat('a', 64)
            && ! isset($r['attendees']) && $r['end']['dateTime'] === '2026-10-06T07:20:00+00:00'
            && $r['extendedProperties']['private']['findwardRequestHash'] === $this->details()->fingerprint()
            && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
            && str_contains($r['description'], '&lt;b&gt;Fixture&lt;/b&gt;'));
    }

    public function test_pending_or_failed_meet_never_confirms_even_with_a_url(): void
    {
        foreach (['pending' => ConsultationEventState::MeetPending, 'failure' => ConsultationEventState::MeetFailed] as $code => $state) {
            $event = $this->event();
            $event['conferenceData'] = ['createRequest' => ['status' => ['statusCode' => $code]],
                'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/fixture']]];
            Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']), 'www.googleapis.com/*' => Http::response($event)]);
            $result = (new GoogleCalendarProvider)->find($this->profile(), str_repeat('a', 64));
            $this->assertNotNull($result);
            $this->assertFalse($result->confirmed);
            $this->assertSame($state, $result->state);
        }
    }

    public function test_server_write_error_has_one_post_and_an_uncertain_outcome(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']), 'www.googleapis.com/*' => Http::response([], 503)]);
        $this->expectException(ConsultationWriteUncertain::class);
        try {
            (new GoogleCalendarProvider)->create($this->profile(), str_repeat('a', 64), $this->details());
        } finally {
            Http::assertSentCount(2);
        }
    }

    public function test_event_id_conflict_reads_the_original_without_another_post(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']),
            'www.googleapis.com/*' => fn (Request $request) => $request->method() === 'POST' ? Http::response([], 409) : Http::response($this->event())]);
        $result = (new GoogleCalendarProvider)->create($this->profile(), str_repeat('a', 64), $this->details());
        $this->assertTrue($result->confirmed);
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/events?')));
    }

    public function test_cancelled_tombstone_is_positive_evidence_and_missing_event_is_not(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']),
            'www.googleapis.com/*' => Http::response(['id' => str_repeat('a', 64), 'status' => 'cancelled'])]);
        $result = (new GoogleCalendarProvider)->find($this->profile(), str_repeat('a', 64));
        $this->assertNotNull($result);
        $this->assertSame(ConsultationEventState::Cancelled, $result->state);
    }

    public function test_recovery_reads_work_without_write_authorization(): void
    {
        config(['consultation.google.writes_authorized' => false]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fixture-access']), 'www.googleapis.com/*' => Http::response([], 404)]);
        $provider = new GoogleCalendarProvider;
        $this->assertTrue($provider->configured(forWrite: false));
        $this->assertNull($provider->find($this->profile(), str_repeat('a', 64)));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/events?'));
    }
}
