<?php

namespace App\Http\Controllers;

use App\Data\Consultation\BookingDetails;
use App\Http\Requests\Consultation\AvailabilityRequest;
use App\Http\Requests\Consultation\BookConsultationRequest;
use App\Services\Consultation\BookingAvailability;
use App\Services\Consultation\BookingConfiguration;
use App\Services\Consultation\ReserveConsultation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Store;

final class ConsultationController extends Controller
{
    public function settings(BookingConfiguration $configuration): JsonResponse
    {
        $profile = $configuration->require();
        $today = CarbonImmutable::now($profile->timezone)->startOfDay();

        return $this->json(['configured' => true, 'timezone' => $profile->timezone, 'duration_minutes' => 20,
            'first_date' => $today->toDateString(), 'last_date' => $today->addDays($profile->daysAhead)->toDateString()]);
    }

    public function availability(AvailabilityRequest $request, BookingConfiguration $configuration, BookingAvailability $availability): JsonResponse
    {
        return $this->json($availability->forDate($configuration->require(), $request->validated('date')));
    }

    public function store(BookConsultationRequest $request, BookingConfiguration $configuration, ReserveConsultation $reserve): JsonResponse
    {
        $data = $request->validated();
        $session = $request->session();
        abort_unless($session instanceof Store, 503);
        $result = $reserve->reserve($configuration->require(), new BookingDetails($data['name'], $data['email'], $data['brief'] ?? '', CarbonImmutable::parse($data['start'])->utc()), $data['idempotency_key'], $session, $request->header('X-Consultation-Reference'));

        return $this->json($result, $result['status'] === 'confirmed' ? 201 : 202);
    }

    public function status(Request $request, string $key, BookingConfiguration $configuration, ReserveConsultation $reserve): JsonResponse
    {
        $session = $request->session();
        abort_unless($session instanceof Store, 503);
        $result = $reserve->status($configuration->require(forRecovery: true), $key, $session, $request->header('X-Consultation-Reference'));

        return $this->json($result, $result['status'] === 'confirmed' ? 200 : 202);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
