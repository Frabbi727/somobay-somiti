<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Services\Reconciliation;
use App\Listeners\RememberUserLocale;
use App\Models\User;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->when(Reconciliation::class)
            ->needs('$sources')
            ->giveTagged('somiti.subledgers');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        Event::listen(LocaleChanged::class, RememberUserLocale::class);

        Gate::define('viewReports', fn (User $user): bool => $user->isStaff());

        Model::preventLazyLoading(! $this->app->isProduction());

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch
                ->locales(['bn', 'en'])
                ->labels([
                    'bn' => 'বাংলা',
                    'en' => 'English',
                ])
                ->userPreferredLocale(function (): string {
                    $user = Auth::user();

                    return $user instanceof User ? $user->locale : (string) config('app.locale');
                });
        });
    }
}
