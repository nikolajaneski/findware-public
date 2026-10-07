<?php

namespace App\Exceptions;

use RuntimeException;

final class ConsultationWriteUncertain extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Calendar write outcome requires reconciliation.');
    }
}
