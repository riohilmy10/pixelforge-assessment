<?php

namespace App\Services\Pixelforge\Exceptions;

/**
 * A 429 or 503 that is worth retrying after a delay. Pixelforge always says how long.
 */
class RetryableException extends PixelforgeException
{
    /**
     * @param  array<string, array<string>>  $errors
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?string $errorType = null,
        ?int $status = null,
        array $errors = [],
        public readonly int $retryAfter = 5,
    ) {
        parent::__construct($message, $errorCode, $errorType, $status, $errors);
    }

    /**
     * Whether retrying can plausibly help. Limits that reset on a longer horizon
     * (the daily quota) can't be fixed by retrying in a few seconds.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode !== 'daily_limit_exceeded';
    }
}
