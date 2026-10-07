<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Settings\Models\SomitiProfile;
use App\Filament\Member\Pages\Auth\MemberLogin;
use App\Filament\Member\Pages\Dashboard;
use App\Http\Middleware\RedirectApplicantsToRegistration;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The member portal (/portal): the same panel experience as the staff panel — left sidebar (a drawer
 * on phones), dark mode, language switch, tables — showing only the signed-in member's own records.
 */
final class MemberPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('member')
            ->path('portal')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(MemberLogin::class)
            ->brandName(fn (): string => SomitiProfile::current()->displayName())
            ->brandLogo(fn (): ?string => SomitiProfile::current()->logoDataUri())
            ->brandLogoHeight('2.25rem')
            ->colors(['primary' => Color::Emerald])
            ->darkMode()
            ->spa(hasPrefetching: true)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->discoverPages(in: app_path('Filament/Member/Pages'), for: 'App\Filament\Member\Pages')
            ->discoverWidgets(in: app_path('Filament/Member/Widgets'), for: 'App\Filament\Member\Widgets')
            ->pages([Dashboard::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RedirectApplicantsToRegistration::class,
            ]);
    }
}
