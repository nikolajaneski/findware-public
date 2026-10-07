<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ConsultationProblem extends RuntimeException
{
    public function __construct(public readonly string $problem, public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['code' => $this->problem, 'message' => $this->getMessage()], $this->httpStatus)
            ->header('Cache-Control', 'private, no-store');
    }

    public static function setup(): self
    {
        return new self('setup_required', 503, 'Consultation booking is not available yet. Please contact us by email.');
    }

    public static function provider(): self
    {
        return new self('calendar_unavailable', 503, 'The calendar could not be checked. Please try again later.');
    }

    public static function conflict(): self
    {
        return new self('slot_unavailable', 409, 'That time is no longer available. Please choose another time.');
    }
}
