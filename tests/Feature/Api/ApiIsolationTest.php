<?php

declare(strict_types=1);

use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Enums\Role;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
| Every member API route (GET) refuses a request with no token, a staff account holding a "member"
| ability token, and a refresh token. New routes inside the member group are covered automatically.
*/

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
});

/**
 * @return list<string>
 */
function memberApiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_starts_with($route->uri(), 'api/v1/') && in_array('GET', $route->methods(), true) && ! str_starts_with($route->uri(), 'api/v1/config/') && $route->uri() !== 'api/v1/statement/pdf-signed')
        ->map(fn (RouteDefinition $route): string => '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()))
        ->values()->all();
}

it('has the routes the app needs, all inside the member group', function (): void {
    expect(memberApiRoutes())->toContain('/api/v1/auth/me', '/api/v1/dashboard/summary', '/api/v1/dues', '/api/v1/payments', '/api/v1/payments/1', '/api/v1/payments/1/receipt', '/api/v1/statement', '/api/v1/statement/pdf', '/api/v1/dividends', '/api/v1/shares/overview', '/api/v1/profile', '/api/v1/notifications');
});

it('refuses every member route without a member access token', function (): void {
    $staffToken = userWithRole(Role::Accountant)->createToken('access:staff', ['member'], now()->addHour())->plainTextToken;
    $refreshToken = app(MemberTokens::class)->issue(app(PortalAccounts::class)->forMember($this->member))['refresh_token'];

    foreach (memberApiRoutes() as $uri) {
        app('auth')->forgetGuards();
        $this->withToken('')->getJson($uri)->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($refreshToken)->getJson($uri)->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($staffToken)->getJson($uri)->assertForbidden();
        $staffToken = userWithRole(Role::Accountant)->createToken('access:staff', ['member'], now()->addHour())->plainTextToken;
    }
});
