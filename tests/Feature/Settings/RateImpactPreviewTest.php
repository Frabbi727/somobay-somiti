<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Actions\DeactivateMember;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateImpactPreviewer;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\ViewRatePlan;
use App\Models\User;
use App\Reports\RateImpactDocument;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    app()->setLocale('en');
    approvedPlan('2026-07', '500'); // ৳500 deposit + ৳10 service per share
    $this->a = onboard(2, '2026-07', ['name_en' => 'Alpha']);
    $this->b = onboard(1, '2026-08', ['name_en' => 'Bravo']);
    $inactive = onboard(3, '2026-07', ['name_en' => 'Charlie']);
    app(DeactivateMember::class)(userWithRole(Role::Secretary), $inactive, 'Paused by committee');
    $this->accountant = userWithRole(Role::Accountant);
});

function draftPlan(string $month, string $unit, array $overrides = []): RatePlan
{
    return app(DraftRatePlan::class)(userWithRole(Role::Accountant), ratePlanData($month, $unit, $overrides));
}

function giveAdvance(Member $member, string $taka, User $actor): void
{
    $amount = Money::ofTaka($taka);

    app(PostJournal::class)($actor, new JournalEntryData(VoucherType::Receipt, CarbonImmutable::parse('2026-10-05'), 'Advance', [
        JournalLineData::debit(account('1101'), $amount),
        JournalLineData::credit(account('2111'), $amount, $member->id),
    ]));
}

it('compares monthly collection for active members', function (): void {
    $preview = app(RateImpactPreviewer::class)->preview(draftPlan('2027-01', '600'));

    expect($preview->previous?->code)->toBe('RP-2026-07-v1')
        ->and($preview->until)->toBeNull()
        ->and($preview->memberCount())->toBe(2)
        ->and($preview->shareCount())->toBe(3)
        ->and($preview->oldMonthlyTotal()->poisha)->toBe(153000)
        ->and($preview->newMonthlyTotal()->poisha)->toBe(183000)
        ->and($preview->monthlyDifference()->poisha)->toBe(30000)
        ->and($preview->warnings)->toBe([]);
});

it('counts shares as they stand in the plan month', function (): void {
    app(ChangeShares::class)(userWithRole(Role::Secretary), $this->b, 2, YearMonth::of(2027, 2), 'more');

    expect(app(RateImpactPreviewer::class)->preview(draftPlan('2027-01', '600'))->shareCount())->toBe(3)
        ->and(app(RateImpactPreviewer::class)->preview(draftPlan('2027-02', '600'))->shareCount())->toBe(5);
});

it('shows how far each advance goes at the old and new rate', function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    giveAdvance($this->a, '2040', $this->accountant);

    $preview = app(RateImpactPreviewer::class)->preview(draftPlan('2027-01', '600'));
    $alpha = $preview->membersWithAdvance()[0];

    expect($preview->membersWithAdvance())->toHaveCount(1)
        ->and($alpha->advance->poisha)->toBe(204000)
        ->and($alpha->coverageOld())->toBe(2)   // 2040 / 1020
        ->and($alpha->coverageNew())->toBe(1)   // 2040 / 1220
        ->and($alpha->shortfall()->poisha)->toBe(40000) // 2 × 1220 − 2040
        ->and($preview->totalShortfall()->poisha)->toBe(40000);
});

it('ends the period before the next approved plan', function (): void {
    approvedPlan('2027-06', '650');

    expect((string) app(RateImpactPreviewer::class)->preview(draftPlan('2027-01', '600'))->until)->toBe('2027-05');
});

it('warns about risky settings', function (): void {
    app()->instance(GeneratedMonths::class, new class implements GeneratedMonths
    {
        public function latest(): ?YearMonth
        {
            return YearMonth::of(2026, 10);
        }
    });

    $keys = fn ($plan): array => array_column(app(RateImpactPreviewer::class)->preview($plan)->warnings, 'key');

    expect($keys(draftPlan('2026-07', '450', [
        'late_fee_mode' => 'percent', 'late_fee_percent' => '2', 'late_fee_base' => 'deposit_only', 'late_fee_frequency' => 'once',
    ])))->toBe([
        'rates.impact.warnings.month_generated',
        'rates.impact.warnings.percent_without_cap',
        'rates.impact.warnings.supersedes',
        'rates.impact.warnings.deposit_decrease',
    ]);
});

it('previews exactly the registration top-up that approval then charges', function (): void {
    $plan = draftPlan('2027-01', '600', [
        'registration_fee_per_share_poisha' => Money::ofTaka('150'),
        'registration_fee_on_rate_increase' => 'difference',
    ]);

    $preview = app(RateImpactPreviewer::class)->preview($plan);

    expect($preview->topUpLotCount)->toBe(2)
        ->and($preview->topUpAmount->poisha)->toBe(15000);

    app(SubmitRatePlan::class)(User::query()->findOrFail($plan->created_by), $plan);
    app(ApproveRatePlan::class)(userWithRole(Role::President), $plan->fresh());
    app(ApproveRatePlan::class)(userWithRole(Role::Secretary), $plan->fresh());

    $charged = Due::query()->where('rate_plan_id', $plan->id)->where('type', DueType::Registration)->get();

    expect($charged)->toHaveCount($preview->topUpLotCount)
        ->and(Money::sum($charged->map(fn ($due) => $due->amount_poisha))->equals($preview->topUpAmount))->toBeTrue();
});

it('renders the preview and exports every member to Excel', function (): void {
    $plan = draftPlan('2027-01', '600');
    $preview = app(RateImpactPreviewer::class)->preview($plan);

    expect(view('filament.rates.impact', ['preview' => $preview])->render())
        ->toContain('৳ 1,530.00')
        ->toContain('৳ 1,830.00')
        ->toContain('+৳ 300.00');

    $path = tempnam(sys_get_temp_dir(), 'impact').'.xlsx';
    file_put_contents($path, app(RateImpactDocument::class)->excel($plan));
    $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
    unlink($path);

    $memberRows = array_values(array_filter($rows, fn (array $row): bool => is_string($row[0]) && str_starts_with($row[0], 'M-')));

    expect($memberRows)->toHaveCount(2)
        ->and($memberRows[0][2])->toEqual(1020)
        ->and($memberRows[0][3])->toEqual(1220);
});

it('downloads the impact from the plan page', function (): void {
    Filament::setCurrentPanel('admin');
    $plan = draftPlan('2027-01', '600');
    $this->actingAs($this->accountant);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('downloadImpact')
        ->assertFileDownloaded('rate-impact-RP-2027-01-v1.xlsx');
});
