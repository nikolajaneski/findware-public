<?php

namespace App\Http\Middleware;

use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';
}
