<?php

use App\Http\Middleware\ConsultationSession;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')
    ->middleware([ConsultationSession::class, HandleInertiaRequests::class])
    ->name('home');

foreach (['approach', 'criteria', 'examples'] as $section) {
    Route::inertia('/'.$section, 'welcome')
        ->middleware([ConsultationSession::class, HandleInertiaRequests::class])
        ->name('marketing.'.$section);
}

Route::inertia('/consultation', 'welcome', ['consultation' => true])
    ->middleware([ConsultationSession::class, HandleInertiaRequests::class])
    ->name('consultation.page');

require __DIR__.'/consultation.php';
