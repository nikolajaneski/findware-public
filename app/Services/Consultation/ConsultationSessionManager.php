<?php

namespace App\Services\Consultation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Session\SessionManager;

final class ConsultationSessionManager extends SessionManager
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
        // Clone configuration: client/admin session and cache settings are never changed.
        $this->config = clone $this->config;
        $this->config->set('session', array_replace($this->config->get('session'), [
            'driver' => 'file', 'files' => $this->config->get('consultation.runtime_path').'/sessions',
            'cookie' => 'findward_consultation_session', 'path' => '/', 'domain' => null,
            'secure' => $container->make('request')->isSecure(), 'http_only' => true, 'same_site' => 'lax',
            'lifetime' => 120, 'encrypt' => false, 'serialization' => 'json',
            'block' => true, 'block_store' => 'file', 'block_lock_seconds' => 120, 'block_wait_seconds' => 1,
        ]));
        $container->make('files')->ensureDirectoryExists($this->config->get('session.files'), 0700);
    }
}
