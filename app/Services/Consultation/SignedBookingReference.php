<?php

namespace App\Services\Consultation;

use App\Data\Consultation\BookingDetails;
use App\Data\Consultation\BookingProfile;
use App\Data\Consultation\BookingRetry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class SignedBookingReference
{
    public function issue(BookingProfile $profile, BookingDetails $details, string $key, string $session): BookingRetry
    {
        $claims = ['v' => 1, 'key' => $key, 'calendar' => $profile->calendarHash(),
            'event' => hash('sha256', 'findward-consultation|'.$profile->calendarHash().'|'.$key),
            'session' => $this->sessionHash($session), 'request' => $details->fingerprint(),
            'start' => $details->start->utc()->toIso8601String(), 'timezone' => $profile->timezone,
            'expires' => CarbonImmutable::now('UTC')->addDays(90)->timestamp];
        $token = Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR));

        return $this->verify($token, $profile, $key, $session);
    }

    public function verify(string $token, BookingProfile $profile, string $key, string $session): BookingRetry
    {
        try {
            if (strlen($token) > 8192) {
                abort(404);
            }
            $c = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($c) || ($c['v'] ?? null) !== 1 || ($c['key'] ?? null) !== $key
                || ! is_string($c['calendar'] ?? null) || ! hash_equals($profile->calendarHash(), $c['calendar'])
                || ! is_string($c['session'] ?? null) || ! hash_equals($this->sessionHash($session), $c['session'])
                || ! is_int($c['expires'] ?? null) || $c['expires'] < CarbonImmutable::now('UTC')->timestamp
                || ! is_string($c['event'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $c['event'])
                || ! is_string($c['request'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $c['request'])
                || ! is_string($c['start'] ?? null) || ! is_string($c['timezone'] ?? null)) {
                abort(404);
            }

            return new BookingRetry($key, $c['event'], $c['request'], CarbonImmutable::parse($c['start'])->utc(),
                $c['timezone'], $token);
        } catch (Throwable) {
            abort(404);
        }
    }

    private function sessionHash(string $session): string
    {
        return hash_hmac('sha256', $session, (string) config('app.key'));
    }
}
