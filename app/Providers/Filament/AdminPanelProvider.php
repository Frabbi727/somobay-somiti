<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Integrity\Models\IntegrityRun;
use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Area;
use App\Filament\Navigation\NavGroup;
use App\Filament\Pages\Auth\StaffLogin;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(fn (): string => SomitiProfile::current()->displayName())
            ->brandLogo(fn (): ?string => SomitiProfile::current()->logoDataUri())
            ->brandLogoHeight('2.25rem')
            ->login(StaffLogin::class)
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ], isRequired: fn (): bool => (bool) config('somiti.require_mfa'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->darkMode()
            ->spa(hasPrefetching: true)
            ->unsavedChangesAlerts()
            ->databaseTransactions()
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(NavGroup::navigationGroups())
            ->databaseNotifications()
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): View|string => self::integrityBanner())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
            ]);
    }

    /**
     * A red banner on every staff page while the latest integrity run has findings (W7).
     */
    private static function integrityBanner(): View|string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! Area::Accounting->allows($user)) {
            return '';
        }

        $run = IntegrityRun::latestFinished();

        return $run !== null && $run->failed() ? view('filament.integrity-banner', ['run' => $run]) : '';
    }
}
