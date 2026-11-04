<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\GoLiveImportPage;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-01 10:00');
    Filament::setCurrentPanel('admin');
    $this->seed(ChartOfAccountsSeeder::class);
    Storage::fake('local');
    $this->admin = userWithRole(Role::SuperAdmin);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('is open to the super admin and president only', function (Role $role, int $status): void {
    $this->actingAs($role === Role::SuperAdmin ? $this->admin : userWithRole($role))->get(GoLiveImportPage::getUrl())->assertStatus($status);
})->with([[Role::SuperAdmin, 200], [Role::President, 200], [Role::Accountant, 403]]);

it('downloads the template', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(GoLiveImportPage::class)->callAction('downloadTemplate')->assertFileDownloaded('somiti-go-live-template.xlsx');
});

it('uploads, shows errors, then posts a clean sheet with a typed confirmation', function (): void {
    $this->actingAs($this->admin);
    $bad = UploadedFile::fake()->createWithContent('sheet.xlsx', (string) file_get_contents(openingWorkbookFile(['members' => [memberRow(['member_no' => 'x'])]])));
    $good = UploadedFile::fake()->createWithContent('sheet.xlsx', (string) file_get_contents(openingWorkbookFile(['members' => [memberRow(['member_no' => 'M-0001', 'savings' => '100'])], 'society' => [['account_code' => '1101', 'amount' => '100']]])));

    Livewire::test(GoLiveImportPage::class)
        ->callAction('uploadOpening', data: ['file' => $bad, 'go_live_month' => '2026-07'])
        ->assertSee(__('golive.errors.member_no_format'))
        ->assertActionHidden('post')
        ->callAction('uploadOpening', data: ['file' => $good, 'go_live_month' => '2026-07'])
        ->assertActionVisible('post')
        ->assertSee(__('golive.summary.members'))
        ->callAction('post', data: ['confirm_text' => 'POST'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('golive.posted'))
        ->assertActionHidden('uploadOpening')
        ->assertActionHidden('post');

    expect(OpeningImport::query()->sole()->status)->toBe(OpeningImportStatus::Posted);
});
