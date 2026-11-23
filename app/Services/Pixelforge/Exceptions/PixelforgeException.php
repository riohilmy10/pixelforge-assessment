<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;

/**
 * An error returned by Pixelforge that we could not classify further.
 *
 * `errorCode` mirrors the documented `error.code` (`rate_limit_exceeded`, `overloaded`, …).
 * It is not named `code` because Exception already declares that property.
 */
class PixelforgeException extends RuntimeException
{
    /**
     * @param  array<string, array<string>>  $errors  validation errors, keyed by parameter
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorType = null,
        public readonly ?int $status = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
