<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept-Language → bn or en (anything else, or nothing, is Bangla — the society's language).
 */
final class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $first = strtolower(substr(trim((string) $request->header('Accept-Language', 'bn')), 0, 2));
        app()->setLocale($first === 'en' ? 'en' : 'bn');

        return $next($request);
    }
}
