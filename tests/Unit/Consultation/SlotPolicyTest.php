<?php

namespace Tests\Unit\Consultation;

use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BusyWindow;
use App\Services\Consultation\SlotPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SlotPolicyTest extends TestCase
{
    private function profile(string $zone = 'Europe/Skopje', int $buffer = 0): BookingProfile
    {
        return new BookingProfile('fixture@example.test', $zone, array_fill(1, 7, [['00:00', '23:59']]), [], 30, 60, $buffer, $buffer);
    }

    public function test_minimum_lead_boundaries_alignment_and_duration(): void
    {
        $policy = new SlotPolicy;
        $now = CarbonImmutable::parse('2026-10-05T07:00:00Z');
        $this->assertFalse($policy->eligible($this->profile(), $now->addMinutes(40), $now));
        $this->assertTrue($policy->eligible($this->profile(), $now->addMinutes(60), $now));
        $this->assertFalse($policy->eligible($this->profile(), $now->addMinutes(61), $now));
        $this->assertFalse($policy->eligible($this->profile(), $now->addDays(32), $now));
    }

    public function test_buffers_and_half_open_busy_intervals(): void
    {
        $policy = new SlotPolicy;
        $start = CarbonImmutable::parse('2026-10-06T07:00:00Z');
        $busy = [new BusyWindow($start->addMinutes(20), $start->addMinutes(40))];
        $this->assertTrue($policy->free($this->profile(), $start, $busy));
        $this->assertFalse($policy->free($this->profile(buffer: 10), $start, $busy));
    }

    public function test_dst_gap_is_not_invented_and_repeated_wall_times_have_distinct_instants(): void
    {
        $policy = new SlotPolicy;
        $profile = $this->profile('Europe/London');
        $spring = $policy->forDate($profile, '2026-03-29', CarbonImmutable::parse('2026-03-01T00:00:00Z'), []);
        $this->assertSame([], array_values(array_filter($spring, fn ($s): bool => $s->setTimezone('Europe/London')->hour === 1)));
        $autumn = $policy->forDate($profile, '2026-10-25', CarbonImmutable::parse('2026-10-01T00:00:00Z'), []);
        $ones = array_values(array_filter($autumn, fn ($s): bool => $s->setTimezone('Europe/London')->format('H:i') === '01:00'));
        $this->assertCount(2, $ones);
        $this->assertNotEquals($ones[0]->timestamp, $ones[1]->timestamp);
        $this->assertNotSame($ones[0]->setTimezone('Europe/London')->format('P'), $ones[1]->setTimezone('Europe/London')->format('P'));
    }

    public function test_confirmed_weekday_schedule_excludes_weekends_and_closes_at_seventeen(): void
    {
        $policy = new SlotPolicy;
        $profile = new BookingProfile('fixture@example.test', 'Europe/Skopje', array_fill(1, 5, [['09:00', '17:00']]), [], 30, 60, 0, 0);
        $now = CarbonImmutable::parse('2026-10-05T00:00:00Z');
        $friday = $policy->forDate($profile, '2026-10-09', $now, []);
        $this->assertCount(24, $friday);
        $this->assertSame('09:00', $friday[0]->setTimezone('Europe/Skopje')->format('H:i'));
        $this->assertSame('16:40', $friday[23]->setTimezone('Europe/Skopje')->format('H:i'));
        $this->assertSame([], $policy->forDate($profile, '2026-10-10', $now, []));
        $this->assertSame([], $policy->forDate($profile, '2026-10-11', $now, []));
    }
}
