<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\GoLive\Actions\UploadOpeningImport;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    Storage::fake('local');
    $this->admin = userWithRole(Role::SuperAdmin);
});

it('saves a draft with errors and a summary, and replaces it on re-upload', function (): void {
    $upload = app(UploadOpeningImport::class);

    $first = $upload($this->admin, storeOpeningFile(['members' => [memberRow(['member_no' => 'bad'])]], 'first.xlsx'), 'first.xlsx', YearMonth::parse('2026-07'));

    expect($first->status)->toBe(OpeningImportStatus::Draft)
        ->and($first->errors)->not->toBe([]);

    $second = $upload($this->admin, storeOpeningFile(['members' => [memberRow(['member_no' => 'M-0001', 'savings' => '100'])]], 'second.xlsx'), 'second.xlsx', YearMonth::parse('2026-07'));

    expect(OpeningImport::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->errors)->toBe([])
        ->and($second->summary['savings_poisha'])->toBe(10000)
        ->and($second->payload['members'][0]['member_no'])->toBe('M-0001')
        ->and($second->file_sha256)->toBe(hash('sha256', (string) Storage::disk('local')->get('opening-imports/second.xlsx')))
        ->and(Storage::disk('local')->exists('opening-imports/first.xlsx'))->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'opening_import_uploaded')->count())->toBe(2);
});

it('refuses uploads after posting and from users without the permission', function (): void {
    expect(fn () => app(UploadOpeningImport::class)(userWithRole(Role::Accountant), storeOpeningFile([]), 'x.xlsx', YearMonth::parse('2026-07')))
        ->toThrow(AuthorizationException::class);

    OpeningImport::query()->create(['status' => OpeningImportStatus::Posted, 'go_live_month' => YearMonth::parse('2026-07'), 'file_path' => 'p', 'file_name' => 'p', 'file_sha256' => str_repeat('b', 64), 'payload' => [], 'errors' => [], 'summary' => [], 'uploaded_by' => $this->admin->id]);

    expect(fn () => app(UploadOpeningImport::class)($this->admin, storeOpeningFile([]), 'x.xlsx', YearMonth::parse('2026-07')))
        ->toThrow(DomainRuleViolation::class, __('golive.errors.already_posted'));
});
