<?php

namespace Tests\Feature\Consultation;

use App\Contracts\Consultation\CalendarProvider;
use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\CalendarEvent;
use App\Enums\ConsultationEventState;
use App\Exceptions\ConsultationProblem;
use App\Services\Consultation\BookingConfiguration;
use App\Services\Consultation\ConsultationSessionManager;
use App\Services\Consultation\ReserveConsultation;
use Carbon\CarbonImmutable;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeConsultationCalendar;
use Tests\TestCase;

final class ConsultationBookingTest extends TestCase
{
    private FakeConsultationCalendar $calendar;
    private string $runtime;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->calendar = new FakeConsultationCalendar;
        $this->app->instance(CalendarProvider::class, $this->calendar);
        $this->runtime = storage_path('framework/testing/consultation-'.Str::uuid());
        $this->sessionId = Str::random(40);
        $this->withCookie('findward_consultation_session', $this->sessionId);
        config(['consultation.enabled' => true, 'consultation.runtime_path' => $this->runtime,
            'consultation.calendar_id' => 'fixture@example.test', 'consultation.timezone' => 'Europe/Skopje',
            'consultation.weekly_hours' => array_fill(1, 5, [['09:00', '17:00']]),
            'consultation.excluded_dates' => [], 'consultation.google_meet_supported' => true,
            'session.driver' => 'database', 'cache.default' => 'database',
            'cache.stores.file.path' => $this->runtime.'/cache', 'cache.stores.file.lock_path' => $this->runtime.'/cache']);
        // No migrations or SQL are allowed in these Google-only feature tests.
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05T07:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->runtime);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['name' => 'Fixture Attendee', 'email' => 'attendee@example.test', 'brief' => 'Fixture brief',
            'start' => '2026-10-06T07:00:00Z', 'idempotency_key' => (string) Str::uuid(), 'consent' => true];
    }

    private function session(string $id): Store
    {
        $session = (new ConsultationSessionManager($this->app))->driver();
        $session->setId($id);
        $session->start();

        return $session;
    }

    public function test_disabled_setup_does_not_read_google_or_require_sql(): void
    {
        config(['consultation.enabled' => false]);
        $this->getJson('/consultation/settings')->assertStatus(503)->assertJsonPath('code', 'setup_required');
        $this->postJson('/consultation/bookings', $this->payload())->assertStatus(503);
        $this->assertSame(0, $this->calendar->reads);
        $this->assertSame(0, $this->calendar->creates);
    }

    public function test_public_page_settings_and_booking_work_without_sql_session_or_cache(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/consultation/settings')->assertOk();
        $this->postJson('/consultation/bookings', $this->payload())->assertCreated();
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('database', config('cache.default'));
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_clean_consultation_page_is_public_and_does_not_call_calendar(): void
    {
        $this->get('/consultation')->assertOk()
            ->assertInertia(fn ($page) => $page->component('welcome')->where('consultation', true));
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('welcome'));
        $this->getJson('/consultation/settings')->assertOk();
        $this->assertSame(0, $this->calendar->reads);
        $this->assertSame(0, $this->calendar->creates);
    }

    public function test_public_http_cookies_follow_transport_without_changing_global_configuration(): void
    {
        config(['session.secure' => true]);
        $response = $this->getJson('http://localhost/consultation/settings')->assertOk();
        foreach (['XSRF-TOKEN', 'findward_consultation_session'] as $name) {
            $cookie = $response->getCookie($name, false);
            $this->assertNotNull($cookie);
            $this->assertFalse($cookie->isSecure());
            $this->assertSame('lax', $cookie->getSameSite());
        }
        $this->assertTrue(config('session.secure'));
        $this->assertFalse($response->getCookie('XSRF-TOKEN', false)->isHttpOnly());
        $this->assertTrue($response->getCookie('findward_consultation_session', false)->isHttpOnly());
    }

    public function test_public_https_cookies_remain_secure_even_when_global_configuration_is_false(): void
    {
        config(['session.secure' => false]);
        $response = $this->getJson('https://localhost/consultation/settings')->assertOk();
        foreach (['XSRF-TOKEN', 'findward_consultation_session'] as $name) {
            $cookie = $response->getCookie($name, false);
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isSecure());
        }
        $this->assertFalse(config('session.secure'));
    }

    public function test_missing_csrf_token_is_rejected_before_calendar_access(): void
    {
        // Exercise real CSRF validation rather than Laravel's testing-environment shortcut.
        $this->app->instance('env', 'local');
        $this->postJson('/consultation/bookings', $this->payload())->assertStatus(419);
        $this->assertSame(0, $this->calendar->reads);
        $this->assertSame(0, $this->calendar->creates);
    }

    public function test_invalid_owner_configuration_fails_closed(): void
    {
        foreach (['timezone' => 'Invalid/Zone', 'weekly_hours' => [], 'calendar_id' => '', 'google_meet_supported' => false] as $key => $value) {
            $old = config('consultation.'.$key);
            config(['consultation.'.$key => $value]);
            $this->getJson('/consultation/settings')->assertStatus(503);
            config(['consultation.'.$key => $old]);
        }
        $this->assertSame(0, $this->calendar->reads);
    }

    public function test_google_busy_intervals_are_authoritative_and_no_raw_events_are_exposed(): void
    {
        $this->postJson('/consultation/bookings', $this->payload())->assertCreated();
        $response = $this->getJson('/consultation/availability?date=2026-10-06')->assertOk();
        $this->assertSame('2026-10-06T07:20:00+00:00', $response->json('slots.0.start'));
        $this->assertSame(['start', 'end', 'label'], array_keys($response->json('slots.0')));
        $this->assertStringNotContainsString('fixture@example.test', $response->getContent());
    }

    public function test_signed_replay_and_status_confirm_without_duplicate_inserts_or_local_attendee_data(): void
    {
        $payload = $this->payload();
        $response = $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $reference = $response->json('retry_reference');
        $this->assertIsString($reference);
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $this->withHeader('X-Consultation-Reference', $reference)
            ->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertOk();
        $this->assertSame(1, $this->calendar->creates);
        $raw = File::get($this->runtime.'/sessions/'.$this->sessionId);
        foreach ([$payload['name'], $payload['email'], $payload['brief']] as $pii) {
            $this->assertStringNotContainsString($pii, $raw);
        }
    }

    public function test_changed_payload_and_forged_reference_fail_before_provider_access(): void
    {
        $payload = $this->payload();
        $response = $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $finds = $this->calendar->finds;
        $this->postJson('/consultation/bookings', [...$payload, 'email' => 'changed@example.test'])->assertConflict()->assertJsonPath('code', 'idempotency_mismatch');
        $this->withHeader('X-Consultation-Reference', 'tampered')
            ->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertNotFound();
        $this->assertSame($finds, $this->calendar->finds);
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_another_session_or_calendar_cannot_use_a_signed_reference(): void
    {
        $payload = $this->payload();
        $reference = $this->postJson('/consultation/bookings', $payload)->assertCreated()->json('retry_reference');
        $this->withHeader('X-Consultation-Reference', $reference)->withCookie('findward_consultation_session', Str::random(40))
            ->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertNotFound();
        $this->withCookie('findward_consultation_session', $this->sessionId);
        config(['consultation.calendar_id' => 'other-fixture@example.test']);
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertNotFound();
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_uuid_calendar_duration_and_bot_input_are_validated(): void
    {
        foreach (['workspace_id' => 99, 'calendar_id' => 'other', 'duration_minutes' => 40, 'website' => 'bot', 'idempotency_key' => 'not-a-uuid'] as $key => $value) {
            $this->postJson('/consultation/bookings', [...$this->payload(), $key => $value])->assertUnprocessable();
        }
        $this->assertSame(0, $this->calendar->creates);
    }

    public function test_final_busy_recheck_rejects_a_previously_offered_slot(): void
    {
        $this->getJson('/consultation/availability?date=2026-10-06')->assertOk();
        $this->calendar->busyWindows = [new \App\Data\Consultation\BusyWindow(CarbonImmutable::parse('2026-10-06T07:00:00Z'), CarbonImmutable::parse('2026-10-06T07:20:00Z'))];
        $this->postJson('/consultation/bookings', $this->payload())->assertConflict();
        $this->assertSame(0, $this->calendar->creates);
    }

    public function test_accepted_write_timeout_recovers_the_original_google_identity(): void
    {
        $this->calendar->uncertain = true;
        $this->calendar->persistBeforeFailure = true;
        $payload = $this->payload();
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_missing_event_after_timeout_stays_uncertain_and_never_reinserts(): void
    {
        $this->calendar->uncertain = true;
        $payload = $this->payload();
        $this->postJson('/consultation/bookings', $payload)->assertAccepted()->assertJsonPath('recovery_state', 'missing_event');
        $this->postJson('/consultation/bookings', $payload)->assertAccepted();
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertAccepted();
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_valid_signed_reference_recovers_after_session_marker_loss_but_cannot_insert(): void
    {
        $payload = $this->payload();
        $reference = $this->postJson('/consultation/bookings', $payload)->assertCreated()->json('retry_reference');
        File::delete($this->runtime.'/sessions/'.$this->sessionId);
        $this->withHeader('X-Consultation-Reference', $reference)->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertOk();
        $this->calendar->events = [];
        $this->postJson('/consultation/bookings', $payload)->assertAccepted();
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_confirmation_retains_signed_timezone_and_google_meet_link(): void
    {
        $payload = $this->payload();
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        config(['consultation.timezone' => 'Europe/London']);
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertOk()
            ->assertJsonPath('timezone', 'Europe/Skopje')->assertJsonPath('meeting_url', 'https://meet.google.com/fixture');
    }

    public function test_cancelled_and_mismatched_google_events_are_never_reported_confirmed(): void
    {
        $payload = $this->payload();
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $id = array_key_first($this->calendar->events);
        $event = $this->calendar->events[$id];
        $this->calendar->events[$id] = new CalendarEvent($id, $event->start, $event->end, true, null, requestHash: 'wrong');
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertAccepted()->assertJsonPath('recovery_state', 'event_unverified');
        $this->calendar->events[$id] = new CalendarEvent($id, null, null, false, null, ConsultationEventState::Cancelled);
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertConflict()->assertJsonPath('code', 'event_cancelled');
        $this->postJson('/consultation/bookings', $payload)->assertConflict();
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_provider_error_and_meet_failure_keep_truthful_uncertainty(): void
    {
        $payload = $this->payload();
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        $this->calendar->findProblem = ConsultationProblem::provider();
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertAccepted();
        $this->calendar->findProblem = null;
        $id = array_key_first($this->calendar->events);
        $event = $this->calendar->events[$id];
        $this->calendar->events[$id] = new CalendarEvent($id, $event->start, $event->end, false, null,
            ConsultationEventState::MeetFailed, requestHash: $event->requestHash);
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertAccepted()->assertJsonPath('recovery_state', 'meet_failed');
    }

    public function test_one_host_calendar_lock_blocks_an_interleaved_request_without_sql(): void
    {
        $profile = app(BookingConfiguration::class)->require();
        $payload = $this->payload();
        $details = new BookingDetails($payload['name'], $payload['email'], $payload['brief'], CarbonImmutable::parse($payload['start']));
        $blocked = false;
        $this->calendar->onCreate = function () use ($profile, $details, &$blocked): void {
            try {
                app(ReserveConsultation::class)->reserve($profile, $details, (string) Str::uuid(), $this->session(Str::random(40)));
            } catch (ConsultationProblem $problem) {
                $blocked = $problem->problem === 'booking_busy';
            }
        };
        app(ReserveConsultation::class)->reserve($profile, $details, $payload['idempotency_key'], $this->session($this->sessionId));
        $this->assertTrue($blocked);
        $this->assertSame(1, $this->calendar->creates);
    }

    public function test_signed_status_can_read_when_new_public_booking_is_disabled(): void
    {
        $payload = $this->payload();
        $this->postJson('/consultation/bookings', $payload)->assertCreated();
        config(['consultation.enabled' => false]);
        $this->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertOk();
        $this->postJson('/consultation/bookings', $this->payload())->assertStatus(503);
    }

    public function test_public_ip_throttle_does_not_use_sql_cache_or_authentication(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/consultation/settings')->assertOk();
        }
        $this->getJson('/consultation/settings')->assertStatus(429);
    }

    public function test_missing_csrf_is_rejected_before_any_provider_call(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->postJson('/consultation/bookings', $this->payload())->assertStatus(419);
        $this->assertSame(0, $this->calendar->creates);
        $this->assertSame(0, $this->calendar->finds);
    }

    public function test_failed_session_checkpoint_stops_before_google_access(): void
    {
        $profile = app(BookingConfiguration::class)->require();
        $payload = $this->payload();
        $session = new Store('fixture', new \Illuminate\Session\NullSessionHandler, Str::random(40), 'json');
        $session->start();
        try {
            app(ReserveConsultation::class)->reserve($profile,
                new BookingDetails($payload['name'], $payload['email'], $payload['brief'], CarbonImmutable::parse($payload['start'])),
                $payload['idempotency_key'], $session);
            $this->fail('A failed checkpoint cannot permit provider access.');
        } catch (ConsultationProblem $problem) {
            $this->assertSame('setup_required', $problem->problem);
        }
        $this->assertSame(0, $this->calendar->finds);
        $this->assertSame(0, $this->calendar->creates);
    }

    public function test_expired_signed_reference_fails_before_provider_access(): void
    {
        $payload = $this->payload();
        $reference = $this->postJson('/consultation/bookings', $payload)->assertCreated()->json('retry_reference');
        $finds = $this->calendar->finds;
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(91));
        $this->withHeader('X-Consultation-Reference', $reference)
            ->getJson('/consultation/bookings/'.$payload['idempotency_key'])->assertNotFound();
        $this->assertSame($finds, $this->calendar->finds);
        $this->assertSame(1, $this->calendar->creates);
    }
}
