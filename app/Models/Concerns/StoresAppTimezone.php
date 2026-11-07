<?php

namespace App\Models\Concerns;

/**
 * Store every datetime in the app timezone.
 *
 * Eloquent formats a parsed date without converting it, so an ISO string sent
 * by the browser in UTC ("2026-11-14T00:00:00.000Z") would be written as
 * 00:00 Manila time — eight hours early. Converting first keeps the instant.
 */
trait StoresAppTimezone
{
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)
            ->setTimezone(config('app.timezone'))
            ->format($this->getDateFormat());
    }
}
