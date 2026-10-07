<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class ConsultationCsrf extends PreventRequestForgery
{
    protected function newCookie($request, $config)
    {
        // Match the isolated public session cookie without changing global configuration.
        $config['secure'] = $request->isSecure();

        return parent::newCookie($request, $config);
    }
}
