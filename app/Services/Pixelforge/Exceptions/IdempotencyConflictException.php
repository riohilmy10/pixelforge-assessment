<?php

namespace App\Services\Pixelforge\Exceptions;

/**
 * The Idempotency-Key was already used with different parameters. Always a bug on our
 * side: the same key must always describe the same request.
 */
class IdempotencyConflictException extends PixelforgeException {}
