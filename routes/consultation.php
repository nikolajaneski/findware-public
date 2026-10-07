<?php

use App\Http\Controllers\ConsultationController;
use App\Http\Middleware\ConsultationSession;
use App\Http\Middleware\ConsultationThrottle;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public, session-bound, CSRF-protected endpoints for the server-configured owner calendar.
Route::prefix('consultation')->name('consultation.')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, HandleInertiaRequests::class])
    ->middleware(ConsultationSession::class)->group(function (): void {
    Route::get('settings', [ConsultationController::class, 'settings'])->middleware(ConsultationThrottle::class.':30')->name('settings');
    Route::get('availability', [ConsultationController::class, 'availability'])->middleware(ConsultationThrottle::class.':30')->name('availability');
    Route::post('bookings', [ConsultationController::class, 'store'])->middleware(ConsultationThrottle::class.':5')->name('store');
    Route::get('bookings/{key}', [ConsultationController::class, 'status'])->whereUuid('key')->middleware(ConsultationThrottle::class.':20')->name('status');
});
