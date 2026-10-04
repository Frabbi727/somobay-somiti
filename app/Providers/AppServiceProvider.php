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
use App\Domain\Integrity\Checks\AdvanceChain;
use App\Domain\Integrity\Checks\BalancedEntries;
use App\Domain\Integrity\Checks\ControlAccounts;
use App\Domain\Integrity\Checks\DuePaidAmounts;
use App\Domain\Integrity\Checks\DueSnapshots;
use App\Domain\Integrity\Checks\ExpensePostings;
use App\Domain\Integrity\Checks\JournalHashChain;
use App\Domain\Integrity\Checks\PaymentAllocations;
use App\Domain\Integrity\Checks\StatementMatches;
use App\Domain\Integrity\Checks\TransferPostings;
use App\Domain\Integrity\Checks\VoucherSequences;
use App\Domain\Integrity\Events\IntegrityCheckFailed;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Integrity\Listeners\AlertIntegrityFailure;
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
use App\Http\Middleware\EnsurePortalMember;
use App\Listeners\CheckApplicationHealth;
use App\Listeners\RememberUserLocale;
use App\Models\User;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        $this->app->tag([
            BalancedEntries::class,
            ControlAccounts::class,
            PaymentAllocations::class,
            DuePaidAmounts::class,
            AdvanceChain::class,
            VoucherSequences::class,
            DueSnapshots::class,
            JournalHashChain::class,
            ExpensePostings::class,
            TransferPostings::class,
            StatementMatches::class,
        ], 'somiti.integrity_checks');
        $this->app->when(InvariantChecker::class)->needs('$checks')->giveTagged('somiti.integrity_checks');

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

        Livewire::addPersistentMiddleware([EnsurePortalMember::class]);

        Event::listen(IntegrityCheckFailed::class, AlertIntegrityFailure::class);
        Event::listen(LocaleChanged::class, RememberUserLocale::class);
        Event::listen(DiagnosingHealth::class, CheckApplicationHealth::class);
        Event::listen(RatePlanApproved::class, ChargeRegistrationTopUps::class);
        Event::listen([MonthlyDuesGenerated::class, LateFeesApplied::class], ApplyAdvanceAfterCharges::class);
        // Registered after the advance listener so notices show what is still owed.
        Event::listen(MonthlyDuesGenerated::class, SendDuesNoticeSms::class);
        Event::listen(PaymentApproved::class, SendPaymentReceiptSms::class);
        Event::listen(MemberJoined::class, SendWelcomeSms::class);

        Gate::define('viewReports', fn (User $user): bool => $user->isStaff());
        Gate::define('generateDues', fn (User $user): bool => $user->hasAnyOf(Role::Accountant, Role::President));
        Gate::define('refundAdvance', fn (User $user): bool => $user->hasAnyOf(Role::Accountant, Role::President));
        Gate::define('runIntegrityChecks', fn (User $user): bool => $user->hasAnyOf(Role::SuperAdmin, Role::Accountant, Role::Auditor));

        Model::preventLazyLoading(! $this->app->isProduction());

        // SOMITI_SPEC.md §8.1: the built-in navigation actions look the same everywhere.
        CreateAction::configureUsing(fn (CreateAction $action): CreateAction => $action
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->tooltip(fn (CreateAction $action): string|Htmlable|null => $action->getLabel()));
        EditAction::configureUsing(fn (EditAction $action): EditAction => $action
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->tooltip(__('common.edit')));
        ViewAction::configureUsing(fn (ViewAction $action): ViewAction => $action
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->tooltip(__('common.view')));

        // SOMITI_SPEC.md §8.4: every staff table pages, searches and remembers its state the same way.
        Table::configureUsing(function (Table $table): void {
            $table
                ->paginated([10, 25, 50, 100])
                ->defaultPaginationPageOption(25)
                ->extremePaginationLinks()
                ->searchDebounce('400ms')
                ->filtersLayout(FiltersLayout::AboveContentCollapsible)
                ->persistFiltersInSession()
                ->persistSortInSession()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession();
        });

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
