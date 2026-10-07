<?php

namespace App\Services\Consultation;

use App\Exceptions\ConsultationProblem;
use Closure;
use Illuminate\Filesystem\Filesystem;

final class CalendarMutex
{
    public function __construct(private Filesystem $files) {}

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function run(string $calendarHash, Closure $operation): mixed
    {
        $directory = config('consultation.runtime_path').'/locks';
        $this->files->ensureDirectoryExists($directory, 0700);
        $handle = fopen($directory.'/'.$calendarHash.'.lock', 'c');
        if ($handle === false) {
            throw ConsultationProblem::setup();
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new ConsultationProblem('booking_busy', 429, 'The calendar is being checked. Please try again shortly.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
