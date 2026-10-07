<?php

namespace App\Http\Middleware;

use App\Services\Consultation\ConsultationSessionManager;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

final class ConsultationSession
{
    public function handle(Request $request, Closure $next): mixed
    {
        $manager = new ConsultationSessionManager(app());
        $session = new StartSession($manager, fn () => app('cache'));
        try {
            return $session->handle($request, fn (Request $request) => app(ConsultationCsrf::class)->handle($request, $next));
        } catch (LockTimeoutException) {
            return response()->json(['code' => 'booking_busy', 'message' => 'A booking request is still being checked. Please try again shortly.'], 429)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
