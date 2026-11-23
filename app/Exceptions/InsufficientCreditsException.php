<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The user's balance cannot cover the worst-case quote, so the generation is refused
 * before anything is sent to Pixelforge.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNano,
        public readonly int $availableNano,
    ) {
        parent::__construct('Insufficient credits.');
    }
}
