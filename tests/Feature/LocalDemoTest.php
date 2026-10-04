<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Integrity\Models\IntegrityRun;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Services\YearEndCalculator;
use App\Enums\Role;
use App\Filament\Member\Pages\Auth\MemberLogin;
use App\Filament\Member\Pages\Dashboard as MemberDashboard;
use App\Models\User;
use Database\Seeders\LocalDemoSeeder;
use Database\Seeders\StaffUserSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| The local demo (`php artisan somiti:demo`): consistent books, one login per role, ready-to-try queues.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->seed(LocalDemoSeeder::class);
});

it('builds consistent books with every role and work waiting to be approved', function (): void {
    expect(IntegrityRun::query()->latest('id')->first()?->status->value)->toBe('passed')
        ->and(Spatie\Permission\Models\Role::query()->pluck('name')->sort()->values()->all())->toBe(collect(Role::cases())->map->value->sort()->values()->all())
        ->and(Payment::query()->where('status', PaymentStatus::Pending)->count())->toBe(2);

    $figures = app(YearEndCalculator::class)->calculate(FiscalYear::query()->where('start_year', 2025)->sole(), AppropriationRates::defaults());

    expect($figures->isProfit())->toBeTrue()
        ->and($figures->dividends)->not->toBeEmpty();

    assertBooksTieOut();
});

it('lets every staff login into the panel', function (string $email): void {
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::query()->where('email', $email)->sole())
        ->get(Dashboard::getUrl())
        ->assertOk();
})->with(array_keys(StaffUserSeeder::USERS));

it('lets a member sign in to the portal with mobile and password', function (): void {
    Filament::setCurrentPanel('member');

    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01711000001', 'password' => LocalDemoSeeder::PASSWORD])
        ->call('authenticate')
        ->assertRedirect(MemberDashboard::getUrl());

    $this->get(MemberDashboard::getUrl())->assertOk()->assertSee('রহিম উদ্দিন');
});
