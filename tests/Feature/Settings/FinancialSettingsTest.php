<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Governance\Enums\MeetingType;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Services\Quorum;
use App\Domain\Settings\Actions\ApproveFinancialSettings;
use App\Domain\Settings\Actions\CancelFinancialSettings;
use App\Domain\Settings\Actions\ProposeFinancialSettings;
use App\Domain\Settings\Actions\RejectFinancialSettings;
use App\Domain\Settings\Data\FinancialSettingsData;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Domain\Settings\Services\FinancialSettings;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Actions\PrepareYearEnd;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\FinancialSettingsPage;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| T-19: the financial settings that lived only in the server config. One person proposes a complete
| set with a reason; someone else approves it, and only then does it apply.
*/

beforeEach(function (): void {
    config([
        'somiti.expense_president_threshold_poisha' => 1_000_000,
        'somiti.advance_refund_president_threshold_poisha' => 500_000,
        'somiti.approval_reminder_days' => 3,
        'somiti.quorum_bps' => ['committee' => 5001, 'general' => 3334, 'special_general' => 3334],
        'somiti.require_resolution_for' => [],
    ]);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
});

/**
 * @param  array<string, mixed>  $changes  form values over the settings in force
 */
function proposeSettings(User $actor, array $changes, string $reason = 'Committee decision of 1 November'): FinancialSettingsVersion
{
    return app(ProposeFinancialSettings::class)(
        $actor,
        FinancialSettingsData::fromForm([...app(FinancialSettings::class)->current()->toForm(), ...$changes]),
        $reason,
        (string) Str::uuid(),
    );
}

function settingsRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('starts from the config values and applies a change only once someone else approves it', function (): void {
    $settings = app(FinancialSettings::class);
    $expense = new Expense(['amount_poisha' => Money::ofTaka('8000')]);

    expect($settings->expensePresidentThreshold()->poisha)->toBe(1_000_000)
        ->and($expense->needsPresident())->toBeFalse();

    $version = proposeSettings($this->accountant, [
        'expense_president_threshold' => '5000',
        'quorum_committee' => '60',
        'resolution_required_for' => ['investment'],
    ]);

    expect($version->status)->toBe(FinancialSettingsStatus::Pending)
        ->and($settings->current()->expensePresidentThreshold->poisha)->toBe(1_000_000)
        ->and($this->president->fresh()?->notifications()->count())->toBe(1);

    app(ApproveFinancialSettings::class)($this->president, $version);

    expect($settings->expensePresidentThreshold()->poisha)->toBe(500_000)
        ->and($expense->needsPresident())->toBeTrue()
        ->and(app(Quorum::class)->required(MeetingType::Committee, 10))->toBe(6)
        ->and(ResolutionSubject::Investment->isRequired())->toBeTrue()
        ->and(ResolutionSubject::RatePlan->isRequired())->toBeFalse()
        ->and($this->accountant->fresh()?->notifications()->count())->toBe(1)
        ->and($settings->currentVersion()?->id)->toBe($version->id);
});

it('keeps maker and checker apart', function (): void {
    $version = proposeSettings($this->accountant, ['approval_reminder_days' => '5']);

    expect(fn () => app(ApproveFinancialSettings::class)($this->accountant, $version))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveFinancialSettings::class)(userWithRole(Role::SuperAdmin), $version))->toThrow(AuthorizationException::class)
        ->and(fn () => proposeSettings(userWithRole(Role::Cashier), ['approval_reminder_days' => '6']))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('financial_settings_versions')->where('id', $version->id)->update(['status' => 'approved', 'decided_by' => $this->accountant->id, 'decided_at' => now()])))->toThrow(QueryException::class);

    app(ApproveFinancialSettings::class)(userWithRole(Role::Secretary), $version);

    expect(app(FinancialSettings::class)->approvalReminderDays())->toBe(5);
});

it('refuses proposals that break the rules', function (): void {
    expect(settingsRule(fn () => proposeSettings($this->accountant, ['approval_reminder_days' => '5'], 'ok')))->toBe('financial_settings.errors.reason_required')
        ->and(settingsRule(fn () => proposeSettings($this->accountant, [])))->toBe('financial_settings.errors.no_change')
        ->and(settingsRule(fn () => proposeSettings($this->accountant, ['expense_president_threshold' => '0'])))->toBe('financial_settings.errors.threshold_positive')
        ->and(settingsRule(fn () => proposeSettings($this->accountant, ['approval_reminder_days' => '31'])))->toBe('financial_settings.errors.reminder_days')
        ->and(settingsRule(fn () => proposeSettings($this->accountant, ['quorum_general' => '0'])))->toBe('financial_settings.errors.quorum_range')
        ->and(settingsRule(fn () => proposeSettings($this->accountant, ['reserve' => '10'])))->toBe('year_end.errors.rate_bounds')
        ->and(settingsRule(fn () => proposeSettings($this->accountant, ['financing_society' => true, 'bad_debt_fund' => '5'])))->toBe('year_end.errors.rate_bounds');

    proposeSettings($this->accountant, ['approval_reminder_days' => '5']);

    expect(settingsRule(fn () => proposeSettings($this->accountant, ['approval_reminder_days' => '7'])))->toBe('financial_settings.errors.pending_exists');
});

it('changes nothing when rejected or withdrawn, and decides a proposal only once', function (): void {
    $first = proposeSettings($this->accountant, ['approval_reminder_days' => '5']);

    expect(settingsRule(fn () => app(RejectFinancialSettings::class)($this->president, $first, 'no')))->toBe('financial_settings.errors.reason_required');

    app(RejectFinancialSettings::class)($this->president, $first, 'Keep three days');

    expect(settingsRule(fn () => app(ApproveFinancialSettings::class)($this->president, $first)))->toBe('financial_settings.errors.not_pending');

    $second = proposeSettings($this->accountant, ['approval_reminder_days' => '7']);

    expect(fn () => app(CancelFinancialSettings::class)($this->president, $second))->toThrow(AuthorizationException::class);

    app(CancelFinancialSettings::class)($this->accountant, $second);

    expect(app(FinancialSettings::class)->approvalReminderDays())->toBe(3)
        ->and(FinancialSettingsVersion::query()->pluck('status')->map->value->all())->toBe(['rejected', 'cancelled']);
});

it('never edits or deletes a decided version, and allows one waiting proposal', function (): void {
    $version = proposeSettings($this->accountant, ['approval_reminder_days' => '5']);
    app(ApproveFinancialSettings::class)($this->president, $version);

    expect(fn () => DB::transaction(fn () => DB::table('financial_settings_versions')->where('id', $version->id)->update(['reason' => 'changed later'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('financial_settings_versions')->where('id', $version->id)->delete()))->toThrow(QueryException::class);

    proposeSettings($this->accountant, ['approval_reminder_days' => '6']);

    expect(fn () => DB::transaction(fn () => DB::table('financial_settings_versions')->insert([
        'settings' => '{}', 'reason' => 'second waiting', 'status' => 'pending', 'idempotency_key' => (string) Str::uuid(),
        'proposed_by' => $this->accountant->id, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('returns the first proposal when the same form is sent twice', function (): void {
    $key = (string) Str::uuid();
    $data = FinancialSettingsData::fromForm([...app(FinancialSettings::class)->current()->toForm(), 'approval_reminder_days' => '5']);

    $first = app(ProposeFinancialSettings::class)($this->accountant, $data, 'Committee decision', $key);
    $again = app(ProposeFinancialSettings::class)($this->accountant, $data, 'Committee decision', $key);

    expect($again->id)->toBe($first->id)->and(FinancialSettingsVersion::query()->count())->toBe(1);
});

it('feeds the year-end defaults, and freezes year-end choices while a year-end waits', function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $fy = app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');
    onboard(1, '2026-07');
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '12000', '2026-12-15'));

    app(ApproveFinancialSettings::class)($this->president, proposeSettings($this->accountant, ['reserve' => '20']));

    expect(AppropriationRates::defaults()->reserve->value)->toBe(2000);

    travelTo('2027-07-05');

    foreach ($fy->periods()->orderBy('sequence')->get()->slice(0, -1) as $period) {
        app(LockPeriod::class)($this->accountant, $period);
    }

    app(PrepareYearEnd::class)($this->accountant, $fy, AppropriationRates::defaults());

    $yearEnd = proposeSettings($this->accountant, ['statutory_base' => 'after_loss_offset']);

    expect(settingsRule(fn () => app(ApproveFinancialSettings::class)($this->president, $yearEnd)))->toBe('financial_settings.errors.year_end_in_progress');

    app(CancelFinancialSettings::class)($this->accountant, $yearEnd);
    app(ApproveFinancialSettings::class)($this->president, proposeSettings($this->accountant, ['approval_reminder_days' => '4']));

    expect(app(FinancialSettings::class)->approvalReminderDays())->toBe(4);
    CarbonImmutable::setTestNow();
});

it('is proposed and approved from the settings page', function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    $this->actingAs($this->accountant);

    Livewire::test(FinancialSettingsPage::class)
        ->assertSee(__('financial_settings.in_force_defaults'))
        ->fillForm(['expense_president_threshold' => '7,500', 'reason' => 'Committee decision of 1 November'])
        ->callAction('proposeSettings')
        ->assertHasNoActionErrors()
        ->assertNotified(__('financial_settings.notifications.proposed'));

    $version = FinancialSettingsVersion::query()->sole();

    $this->actingAs($this->president);

    Livewire::test(FinancialSettingsPage::class)
        ->assertCanSeeTableRecords([$version])
        ->assertSee('৳ 7,500.00')
        ->callAction(TestAction::make('approveSettings')->table($version), data: ['confirm_text' => (string) $version->id])
        ->assertHasNoActionErrors()
        ->assertNotified(__('financial_settings.notifications.approved'));

    expect(app(FinancialSettings::class)->expensePresidentThreshold()->poisha)->toBe(750_000)
        ->and(FinancialSettingsPage::getNavigationBadge())->toBeNull();

    app()->setLocale('bn');
    $this->get(FinancialSettingsPage::getUrl())->assertOk()->assertSee('আর্থিক সেটিংস');
    $this->actingAs(userWithRole(Role::Cashier))->get(FinancialSettingsPage::getUrl())->assertForbidden();
});
