<?php

namespace App\Providers;

use App\Contracts\Consultation\CalendarProvider;
use App\Services\Consultation\GoogleCalendarProvider;
use Illuminate\Support\ServiceProvider;

final class ConsultationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CalendarProvider::class, GoogleCalendarProvider::class);
    }
}
