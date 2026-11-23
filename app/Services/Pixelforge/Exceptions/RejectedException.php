<?php

namespace App\Services\Pixelforge\Exceptions;

/**
 * The request was well formed but Pixelforge refused it. Nothing was created or billed.
 */
class RejectedException extends PixelforgeException {}
