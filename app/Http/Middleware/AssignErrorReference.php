<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ErrorReference;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request its error reference up front, so it is in the log context before anything
 * can fail.
 */
final class AssignErrorReference
{
    public function handle(Request $request, Closure $next): Response
    {
        ErrorReference::current();

        return $next($request);
    }
}
