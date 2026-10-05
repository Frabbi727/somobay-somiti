<?php

declare(strict_types=1);

use App\Http\Api\ApiExceptionRenderer;
use App\Http\Middleware\EnsureMemberAccess;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.locale' => SetApiLocale::class,
            'abilities' => CheckAbilities::class,
            'member' => EnsureMemberAccess::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('portal', 'portal/*')
            ? route('filament.member.auth.login')
            : route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
