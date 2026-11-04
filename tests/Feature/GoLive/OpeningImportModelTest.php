<?php

declare(strict_types=1);

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function draftImport(array $overrides = []): OpeningImport
{
    return OpeningImport::query()->create([
        'status' => OpeningImportStatus::Draft,
        'go_live_month' => YearMonth::parse('2026-07'),
        'file_path' => 'opening-imports/x.xlsx',
        'file_name' => 'x.xlsx',
        'file_sha256' => str_repeat('a', 64),
        'payload' => [], 'errors' => [], 'summary' => [],
        'uploaded_by' => userWithRole(Role::SuperAdmin)->id,
        ...$overrides,
    ]);
}

it('allows only one posted import', function (): void {
    draftImport(['status' => OpeningImportStatus::Posted]);

    expect(fn () => draftImport(['status' => OpeningImportStatus::Posted]))->toThrow(QueryException::class);
});

it('never changes or deletes a posted import', function (): void {
    $import = draftImport(['status' => OpeningImportStatus::Posted]);

    expect(fn () => $import->update(['file_name' => 'y.xlsx']))->toThrow(ImmutableRecord::class)
        ->and(fn () => $import->delete())->toThrow(ImmutableRecord::class);
});

it('accepts opening kinds and the opening flag on dues', function (): void {
    expect(DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'advance_entries_kind_valid'")->def)->toContain("'opening'")
        ->and(DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'investment_ledger_kind_valid'")->def)->toContain("'opening'")
        ->and(Schema::hasColumn('dues', 'opening'))->toBeTrue();
});

it('lets staff see the import and only permitted roles upload or post', function (Role $role, bool $may): void {
    $user = userWithRole($role);

    expect($user->can('viewAny', OpeningImport::class))->toBeTrue()
        ->and($user->can('create', OpeningImport::class))->toBe($may)
        ->and($user->can('delete', draftImport()))->toBeFalse();
})->with([[Role::SuperAdmin, true], [Role::President, true], [Role::Accountant, false], [Role::Auditor, false]]);
