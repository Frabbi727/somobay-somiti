<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Contributions\Contracts\AdvanceBalances;
use App\Domain\Contributions\Events\LateFeesApplied;
use App\Domain\Contributions\Events\MonthlyDuesGenerated;
use App\Domain\Contributions\Events\PaymentApproved;
use App\Domain\Contributions\Listeners\ApplyAdvanceAfterCharges;
use App\Domain\Contributions\Listeners\ChargeRegistrationTopUps;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\AdvanceSubledger;
use App\Domain\Contributions\Services\DueLedger;
use App\Domain\Members\Events\MemberJoined;
use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Gateways\BulkSmsBdGateway;
use App\Domain\Notifications\Gateways\LogSmsGateway;
use App\Domain\Notifications\Listeners\SendDuesNoticeSms;
use App\Domain\Notifications\Listeners\SendPaymentReceiptSms;
use App\Domain\Notifications\Listeners\SendWelcomeSms;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Contracts\RatePlanUsage;
use App\Domain\Settings\Events\RatePlanApproved;
use App\Enums\Role;
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
        $this->app->bind(GeneratedMonths::class, DueLedger::class);
        $this->app->bind(RatePlanUsage::class, DueLedger::class);
        $this->app->bind(AdvanceBalances::class, AdvanceLedger::class);
        $this->app->tag([AdvanceSubledger::class], 'somiti.subledgers');

        $this->app->bind(SmsGateway::class, fn (): SmsGateway => match (config('services.sms.driver')) {
            'bulksmsbd' => new BulkSmsBdGateway([
                'url' => (string) config('services.sms.bulksmsbd.url'),
                'api_key' => (string) config('services.sms.bulksmsbd.api_key'),
                'sender_id' => (string) config('services.sms.bulksmsbd.sender_id'),
            ]),
            default => new LogSmsGateway,
        });

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
        Event::listen(RatePlanApproved::class, ChargeRegistrationTopUps::class);
        Event::listen([MonthlyDuesGenerated::class, LateFeesApplied::class], ApplyAdvanceAfterCharges::class);
        // Registered after the advance listener so notices show what is still owed.
        Event::listen(MonthlyDuesGenerated::class, SendDuesNoticeSms::class);
        Event::listen(PaymentApproved::class, SendPaymentReceiptSms::class);
        Event::listen(MemberJoined::class, SendWelcomeSms::class);

        Gate::define('viewReports', fn (User $user): bool => $user->isStaff());
        Gate::define('generateDues', fn (User $user): bool => $user->hasAnyOf(Role::Accountant, Role::President));
        Gate::define('refundAdvance', fn (User $user): bool => $user->hasAnyOf(Role::Accountant, Role::President));

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
