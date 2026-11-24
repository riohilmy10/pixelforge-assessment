<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The usage report exposes every customer's spend, so it is operator-only. Operators
 * are provisioned explicitly with `php artisan pixelforge:make-admin`; registration
 * never grants this access.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin !== true) {
            abort(403, 'This page is for operators only.');
        }

        return $next($request);
    }
}
