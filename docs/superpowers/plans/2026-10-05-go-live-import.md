# Go-live Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a society load all existing members, their savings/advance/arrears, its own cash, bank, fund and investment balances from one Excel workbook, preview it, and post it once as a balanced opening position.

**Architecture:** A new `app/Domain/GoLive` module. A reader turns the workbook into raw string rows; a validator turns raw rows into typed rows plus a list of errors; a pure `OpeningBalanceSheet` turns typed rows into journal lines and a summary. `UploadOpeningImport` stores a draft (raw rows + errors + summary); `PostOpeningImport` re-validates the stored rows and creates everything in one transaction. Small guards in Contributions/Integrity keep the normal engine correct around opening data.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Filament 5, PhpOffice/PhpSpreadsheet (via maatwebsite/excel), Pest, Larastan 8, Pint.

**Spec:** `docs/superpowers/specs/2026-10-05-go-live-import-design.md`

## Global Constraints

- Every file starts with `declare(strict_types=1);`; every class in `app/Domain` is `final`; DTOs are `final readonly class`.
- No `float`, float casts, float literals, `round`/`floor`/`ceil`/`number_format`/`floatval`/`fdiv` in `app/Domain` (arch test `tests/Arch/ArchTest.php`). Money is `App\Support\Money\Money` (int poisha); parse input with `Money::tryOfTaka()`; percentages with `Bps::ofPercent()`.
- Filament classes never write models or use `DB::` (arch test). They call one Action through `DomainActionRunner::run(fn (User $actor) => …)`.
- Financial Actions: `Gate::forUser($actor)->authorize(...)`, `DB::transaction(..., attempts: 3)`, `lockForUpdate()` on what they mutate, `DomainRuleViolation::because('<file>.<key>', [...])`, wrapped in `$this->causer->withCauser($actor, fn () => …)`.
- Every user-facing string goes through `__()` and exists in both `lang/bn/*.php` and `lang/en/*.php`.
- Every Filament action has icon, tooltip, color and a confirmation tier registered in `tests/Feature/ActionInventoryTest.php::expectedTiers()`.
- Posted journals, dues amounts and posted imports never change; no hard deletes.
- After each task: `php artisan test --compact <touched test files>`, `vendor/bin/pint --dirty --format agent`, `composer analyse`.

## Review Focus

1. **Excel turns typed values into numbers** — a mobile `01712345678` typed into a General cell arrives as `1712345678`, a money cell as `1005.2500000001`. Expected: the mobile is read correctly; a money value with more than 2 decimals is an error, never silently rounded. (Tests in Task 2 and Task 3.)
2. **Bangla digits anywhere** — `১,০০৫.২৫`, `২০২০-০৭-০১`, `৩` shares. Expected: read exactly like English digits. (Task 3.)
3. **Two investments on the same 13xx account in the opening voucher** — expected: the nightly integrity check stays clean. (Task 6.)
4. **Double post** (double click / two tabs) — expected: exactly one opening voucher; the second attempt is refused. (Task 7.)
5. **Re-uploading after a fix** replaces the draft instead of piling up drafts, and uploading after posting is refused. (Task 5.)

---

## File Structure

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_10_05_000004_create_opening_imports_table.php` | table, `dues.opening`, kind CHECK constraints |
| `app/Domain/GoLive/Enums/OpeningImportStatus.php` | Draft / Posted |
| `app/Domain/GoLive/Models/OpeningImport.php` | the draft/posted record (posted rows immutable) |
| `app/Policies/OpeningImportPolicy.php` | view = staff; upload/post = `Permission::OpeningImport` |
| `app/Domain/GoLive/Services/OpeningTemplate.php` | column definitions + template `.xlsx` |
| `app/Domain/GoLive/Data/OpeningWorkbook.php` | raw string rows per sheet (serialisable) |
| `app/Domain/GoLive/Services/OpeningWorkbookReader.php` | `.xlsx` → `OpeningWorkbook` |
| `app/Domain/GoLive/Data/OpeningMemberRow.php`, `OpeningSocietyRow.php`, `OpeningInvestmentRow.php`, `OpeningImportError.php`, `ValidatedOpening.php` | typed rows and errors |
| `app/Domain/GoLive/Services/OpeningImportValidator.php` | raw → typed + errors |
| `app/Domain/GoLive/Services/OpeningBalanceSheet.php` | typed → summary and journal lines (pure) |
| `app/Domain/GoLive/Services/GoLiveMonth.php` | the posted go-live month, or null |
| `app/Domain/GoLive/Actions/UploadOpeningImport.php`, `PostOpeningImport.php` | the two writes |
| `app/Filament/Clusters/Settings/Pages/GoLiveImportPage.php` + `resources/views/filament/clusters/settings/go-live-import.blade.php` | the page |
| `lang/{bn,en}/golive.php` | strings |
| Modify: `AdvanceEntryKind`, `InvestmentEntryKind`, `ShareChanger`, `GenerateMonthlyDues`, `ApplyLateFees`, `InvestmentPostings` | engine guards |

---

### Task 1: Schema, enums, model and policy

**Files:**
- Create: `database/migrations/2026_10_05_000004_create_opening_imports_table.php`
- Create: `app/Domain/GoLive/Enums/OpeningImportStatus.php`, `app/Domain/GoLive/Models/OpeningImport.php`, `app/Policies/OpeningImportPolicy.php`, `lang/en/golive.php`, `lang/bn/golive.php`
- Modify: `app/Domain/Contributions/Enums/AdvanceEntryKind.php` (add `Opening`), `app/Domain/Investments/Enums/InvestmentEntryKind.php` (add `Opening`), `lang/{bn,en}/payments.php` (`advance_kind.opening`), `lang/{bn,en}/investments.php` (`entry_kind.opening`)
- Test: `tests/Feature/GoLive/OpeningImportModelTest.php`

**Interfaces:**
- Produces: `OpeningImport` with properties `id, status: OpeningImportStatus, go_live_month: YearMonth, file_path, file_name, file_sha256, payload: array, errors: array, summary: array, journal_entry_id: ?int, uploaded_by: int, posted_by: ?int, posted_at: ?CarbonImmutable`; `OpeningImportStatus::{Draft, Posted}`; `AdvanceEntryKind::Opening = 'opening'`; `InvestmentEntryKind::Opening = 'opening'`; `dues.opening` boolean (default false).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
```

(Add `use Illuminate\Support\Facades\Schema;`.)

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningImportModelTest.php`
Expected: FAIL — class `OpeningImportStatus` not found.

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live import (docs/superpowers/specs/2026-10-05-go-live-import-design.md): the draft/posted
 * import record, the "opening" flag on dues and the "opening" advance/investment ledger kinds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 10);
            $table->date('go_live_month');
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->jsonb('payload');
            $table->jsonb('errors');
            $table->jsonb('summary');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE opening_imports ADD CONSTRAINT opening_imports_status_valid CHECK (status IN ('draft', 'posted'))");
        DB::statement("CREATE UNIQUE INDEX opening_imports_one_posted ON opening_imports ((true)) WHERE status = 'posted'");
        DB::statement("CREATE UNIQUE INDEX opening_imports_one_draft ON opening_imports ((true)) WHERE status = 'draft'");

        Schema::table('dues', function (Blueprint $table): void {
            $table->boolean('opening')->default(false);
        });

        DB::statement('ALTER TABLE advance_ledger_entries DROP CONSTRAINT advance_entries_kind_valid');
        DB::statement("ALTER TABLE advance_ledger_entries ADD CONSTRAINT advance_entries_kind_valid CHECK (kind IN ('payment_surplus', 'applied_to_due', 'refund', 'reversal', 'fee_waiver', 'exit_transfer', 'exit_settlement', 'opening'))");
        DB::statement('ALTER TABLE investment_ledger_entries DROP CONSTRAINT investment_ledger_kind_valid');
        DB::statement("ALTER TABLE investment_ledger_entries ADD CONSTRAINT investment_ledger_kind_valid CHECK (kind IN ('disbursement', 'impairment', 'closure', 'opening'))");
    }
};
```

If the `dues` table has an immutability trigger that lists columns, check `database/migrations/2026_10_03_000003_create_members_and_dues_tables.php`; `opening` is set only on insert, so no trigger change is needed.

- [ ] **Step 4: Enum, model, policy, enum cases, lang**

`app/Domain/GoLive/Enums/OpeningImportStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum OpeningImportStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return __('golive.status.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Posted ? 'success' : 'warning';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::Posted ? Heroicon::OutlinedCheckBadge : Heroicon::OutlinedDocumentMagnifyingGlass;
    }
}
```

`app/Domain/GoLive/Models/OpeningImport.php` (follow `app/Domain/Settings/Models/SomitiProfile.php` for attributes/activity log; use the existing `YearMonthCast` and `ImmutableRecord` the same way `Due` does):

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Models;

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Policies\OpeningImportPolicy;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * The go-live import: a draft (re-uploaded until it has no errors) or the one posted import.
 *
 * @property int $id
 * @property OpeningImportStatus $status
 * @property YearMonth $go_live_month
 * @property string $file_path
 * @property string $file_name
 * @property string $file_sha256
 * @property array<string, mixed> $payload
 * @property list<array<string, mixed>> $errors
 * @property array<string, mixed> $summary
 * @property int|null $journal_entry_id
 * @property int $uploaded_by
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 */
#[UsePolicy(OpeningImportPolicy::class)]
final class OpeningImport extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $import): void {
            if ($import->getOriginal('status') === OpeningImportStatus::Posted) {
                throw ImmutableRecord::for(self::class, $import->getKey());
            }
        });

        self::deleting(function (self $import): void {
            if ($import->status === OpeningImportStatus::Posted) {
                throw ImmutableRecord::for(self::class, $import->getKey());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OpeningImportStatus::class,
            'go_live_month' => YearMonthCast::class,
            'payload' => 'array',
            'errors' => 'array',
            'summary' => 'array',
            'posted_at' => 'immutable_datetime',
        ];
    }
}
```

Check the real namespace of `YearMonthCast` with `grep -rn "class YearMonthCast" app` and of `ImmutableRecord` with `grep -rn "class ImmutableRecord" app` and adjust the imports.

`app/Policies/OpeningImportPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\GoLive\Models\OpeningImport;
use App\Enums\Permission;
use App\Models\User;

/**
 * Staff can see the go-live import; "import members and opening balances" uploads and posts it.
 */
final class OpeningImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, OpeningImport $import): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::OpeningImport);
    }

    public function post(User $user, OpeningImport $import): bool
    {
        return $user->may(Permission::OpeningImport);
    }

    public function update(User $user, OpeningImport $import): bool
    {
        return false;
    }

    public function delete(User $user, OpeningImport $import): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

Add `case Opening = 'opening';` to `AdvanceEntryKind` and `InvestmentEntryKind`. Then run `grep -rn "AdvanceEntryKind::\|InvestmentEntryKind::" app` and add an `Opening` arm to any exhaustive `match` without a `default`. Add the labels:

- `lang/en/payments.php` → `'advance_kind' => [..., 'opening' => 'Opening balance']`; `lang/bn/payments.php` → `'opening' => 'প্রারম্ভিক জের'`
- `lang/en/investments.php` → `'entry_kind' => [..., 'opening' => 'Opening balance']`; bn → `'প্রারম্ভিক জের'`

Create `lang/en/golive.php` and `lang/bn/golive.php` with at least:

```php
// en
return [
    'title' => 'Go-live import',
    'subheading' => 'Bring in existing members and opening balances once, before using the app.',
    'status' => ['draft' => 'Draft', 'posted' => 'Posted'],
];
// bn
return [
    'title' => 'চালু-করার আমদানি',
    'subheading' => 'অ্যাপ ব্যবহারের আগে একবার বিদ্যমান সদস্য ও প্রারম্ভিক জের আনুন।',
    'status' => ['draft' => 'খসড়া', 'posted' => 'পোস্ট করা হয়েছে'],
];
```

Later tasks add keys to these two files.

- [ ] **Step 5: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningImportModelTest.php && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS, Pint passed, 0 errors.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Go-live import: opening_imports table, opening kinds and policy"
```

---

### Task 2: Template and workbook reader

**Files:**
- Create: `app/Domain/GoLive/Services/OpeningTemplate.php`, `app/Domain/GoLive/Data/OpeningWorkbook.php`, `app/Domain/GoLive/Services/OpeningWorkbookReader.php`
- Modify: `lang/{bn,en}/golive.php` (`columns.*`, `errors.unreadable`, `errors.missing_sheet`, `errors.missing_column`, `instructions`)
- Test: `tests/Feature/GoLive/OpeningWorkbookTest.php`

**Interfaces:**
- Produces:
  - `OpeningTemplate::SHEETS` = `['members' => 'Members', 'society' => 'Society', 'investments' => 'Investments']`
  - `OpeningTemplate::COLUMNS: array<string, list<string>>` keyed by `members|society|investments`
  - `OpeningTemplate::binary(): string` (xlsx bytes)
  - `OpeningWorkbook(array $members, array $society, array $investments)`, each `list<array<string, string>>` where every row has `'_row' => '<excel row number>'` plus one string per column; `toArray(): array{members: list<...>, society: list<...>, investments: list<...>}`; `static fromArray(array $data): self`
  - `OpeningWorkbookReader::read(string $absolutePath): OpeningWorkbook` — throws `DomainRuleViolation` for unreadable file, missing sheet or missing column

Sheet layout: row 1 = machine keys (`member_no`, …), row 2 = labels "বাংলা / English", data from row 3. Every column is formatted as Text (`@`) so Excel keeps leading zeros and exact decimals. Blank rows are skipped.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\GoLive\Services\OpeningTemplate;
use App\Domain\GoLive\Services\OpeningWorkbookReader;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the template with the given rows (keyed by sheet) to a temp file and returns its path.
 *
 * @param  array<string, list<array<string, string|int|null>>>  $rows
 */
function openingWorkbookFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'opening').'.xlsx';
    file_put_contents($path, app(OpeningTemplate::class)->binary());
    $book = IOFactory::load($path);

    foreach ($rows as $sheet => $list) {
        $worksheet = $book->getSheetByName(OpeningTemplate::SHEETS[$sheet]);
        foreach ($list as $index => $row) {
            foreach ($row as $column => $value) {
                $col = array_search($column, OpeningTemplate::COLUMNS[$sheet], true) + 1;
                $worksheet->getCell([$col, $index + 3])->setValue($value);
            }
        }
    }

    (new Xlsx($book))->save($path);

    return $path;
}

it('builds a template with the three data sheets, text columns and bilingual labels', function (): void {
    $path = openingWorkbookFile([]);
    $book = IOFactory::load($path);
    $members = $book->getSheetByName('Members');

    expect($book->getSheetNames())->toBe(['Members', 'Society', 'Investments', 'Instructions'])
        ->and($members->getCell('A1')->getValue())->toBe('member_no')
        ->and($members->getStyle('D3')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and((string) $members->getCell('A2')->getValue())->toContain('/');
});

it('reads rows by header name, skips the label row and blank rows, and keeps text exactly', function (): void {
    $path = openingWorkbookFile(['members' => [
        ['member_no' => 'M-0042', 'mobile' => '01712345678', 'savings' => '১,০০৫.২৫'],
        [],
        ['member_no' => 'M-0043', 'savings' => 1005.25],
    ]]);

    $book = app(OpeningWorkbookReader::class)->read($path);

    expect($book->members)->toHaveCount(2)
        ->and($book->members[0])->toMatchArray(['_row' => '3', 'member_no' => 'M-0042', 'mobile' => '01712345678', 'savings' => '১,০০৫.২৫', 'name_bn' => ''])
        ->and($book->members[1])->toMatchArray(['_row' => '5', 'savings' => '1005.25']);
});

it('passes a number Excel stored with extra decimals through unrounded so validation can refuse it', function (): void {
    $path = openingWorkbookFile(['members' => [['member_no' => 'M-0001', 'savings' => 1005.2500001]]]);

    expect(app(OpeningWorkbookReader::class)->read($path)->members[0]['savings'])->toBe('1005.2500001');
});

it('round-trips through toArray/fromArray', function (): void {
    $book = app(OpeningWorkbookReader::class)->read(openingWorkbookFile(['society' => [['account_code' => '1101', 'amount' => '500']]]));

    expect(\App\Domain\GoLive\Data\OpeningWorkbook::fromArray($book->toArray()))->toEqual($book);
});

it('refuses a file that is not our workbook', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, 'not a spreadsheet');

    expect(fn () => app(OpeningWorkbookReader::class)->read($path))->toThrow(DomainRuleViolation::class);

    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $book->getActiveSheet()->setTitle('Sheet1');
    $other = tempnam(sys_get_temp_dir(), 'y').'.xlsx';
    (new Xlsx($book))->save($other);

    expect(fn () => app(OpeningWorkbookReader::class)->read($other))->toThrow(DomainRuleViolation::class, __('golive.errors.missing_sheet', ['sheet' => 'Members']));
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningWorkbookTest.php`
Expected: FAIL — `OpeningTemplate` not found.

- [ ] **Step 3: Implement**

`app/Domain/GoLive/Services/OpeningTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The go-live workbook: one sheet each for members, society balances and investments, plus
 * instructions. Row 1 holds the column keys the reader matches on, row 2 bilingual labels.
 */
final class OpeningTemplate
{
    public const array SHEETS = ['members' => 'Members', 'society' => 'Society', 'investments' => 'Investments'];

    public const array COLUMNS = [
        'members' => ['member_no', 'name_bn', 'name_en', 'mobile', 'nid', 'joined_on', 'shares', 'savings', 'advance', 'arrears_deposit', 'arrears_fees',
            'nominee1_name', 'nominee1_relation', 'nominee1_percent', 'nominee2_name', 'nominee2_relation', 'nominee2_percent'],
        'society' => ['account_code', 'amount'],
        'investments' => ['type', 'institution', 'instrument_no', 'principal', 'invested_on', 'matures_on', 'expected_rate_percent', 'funded_from'],
    ];

    /** Rows formatted as text in the template (data area). */
    private const int TEXT_ROWS = 2000;

    public function binary(): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach (self::SHEETS as $key => $title) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);

            foreach (self::COLUMNS[$key] as $index => $column) {
                $sheet->getCell([$index + 1, 1])->setValue($column);
                $sheet->getCell([$index + 1, 2])->setValue(__('golive.columns.'.$column, [], 'bn').' / '.__('golive.columns.'.$column, [], 'en'));
                $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
            }

            $last = $sheet->getHighestColumn();
            $sheet->getStyle('A1:'.$last.'2')->getFont()->setBold(true);
            $sheet->getStyle('A3:'.$last.(self::TEXT_ROWS + 2))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->freezePane('A3');
        }

        $instructions = $book->createSheet();
        $instructions->setTitle('Instructions');

        foreach ([...__('golive.instructions', [], 'bn'), '', ...__('golive.instructions', [], 'en')] as $row => $line) {
            $instructions->getCell([1, $row + 1])->setValue($line);
        }

        $book->setActiveSheetIndex(0);
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $binary = (string) stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $binary;
    }
}
```

`__('golive.instructions')` is a `list<string>` in each lang file (rules from spec §3, one per line; include "Fill one row per member", "Money: up to 2 decimals, English or Bangla digits", "Dates: YYYY-MM-DD", "Member numbers: M-0001 form", "Advance and arrears cannot both be filled for one member", "Society: allowed account codes 1101, 1111, 1121, 1122, 2211, 3101, 3201, 3202, 3203", "Investment types: fixed_deposit, savings_certificate, government_securities, company_securities, cooperative, other", "Funded from: cash, bank, bkash, nagad"). Add `golive.columns.<key>` for every column in both languages (e.g. en `'member_no' => 'Member no.'`, bn `'member_no' => 'সদস্য নং'`).

`app/Domain/GoLive/Data/OpeningWorkbook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

/**
 * The workbook's rows as text, exactly as typed; '_row' is the Excel row number for error messages.
 */
final readonly class OpeningWorkbook
{
    /**
     * @param  list<array<string, string>>  $members
     * @param  list<array<string, string>>  $society
     * @param  list<array<string, string>>  $investments
     */
    public function __construct(
        public array $members,
        public array $society,
        public array $investments,
    ) {}

    /**
     * @return array{members: list<array<string, string>>, society: list<array<string, string>>, investments: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return ['members' => $this->members, 'society' => $this->society, 'investments' => $this->investments];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = fn (string $key): array => array_values(array_map(
            fn (mixed $row): array => array_map(fn (mixed $value): string => (string) $value, (array) $row),
            (array) ($data[$key] ?? []),
        ));

        return new self($rows('members'), $rows('society'), $rows('investments'));
    }
}
```

`app/Domain/GoLive/Services/OpeningWorkbookReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the go-live workbook into text rows. Cells are read as the text Excel shows (or the
 * number's full PHP string), never converted to numbers here, so nothing is rounded.
 */
final class OpeningWorkbookReader
{
    public function read(string $absolutePath): OpeningWorkbook
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $book = $reader->load($absolutePath);
        } catch (Throwable) {
            throw DomainRuleViolation::because('golive.errors.unreadable');
        }

        $rows = [];

        foreach (OpeningTemplate::SHEETS as $key => $title) {
            $sheet = $book->getSheetByName($title) ?? throw DomainRuleViolation::because('golive.errors.missing_sheet', ['sheet' => $title]);
            $rows[$key] = $this->rows($sheet, OpeningTemplate::COLUMNS[$key], $title);
        }

        $book->disconnectWorksheets();

        return new OpeningWorkbook($rows['members'], $rows['society'], $rows['investments']);
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function rows(Worksheet $sheet, array $columns, string $title): array
    {
        $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $positions = [];

        for ($col = 1; $col <= $highestColumn; $col++) {
            $header = trim((string) $sheet->getCell([$col, 1])->getValue());
            if ($header !== '') {
                $positions[$header] = $col;
            }
        }

        foreach ($columns as $column) {
            if (! isset($positions[$column])) {
                throw DomainRuleViolation::because('golive.errors.missing_column', ['sheet' => $title, 'column' => $column]);
            }
        }

        $rows = [];

        for ($row = 3, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $values = [];

            foreach ($columns as $column) {
                $values[$column] = trim((string) $sheet->getCell([$positions[$column], $row])->getValue());
            }

            if (implode('', $values) !== '') {
                $rows[] = ['_row' => (string) $row, ...$values];
            }
        }

        return $rows;
    }
}
```

(Import `Coordinate` with a `use` line instead of the fully qualified name.) `(string)` of a PHP float gives the shortest exact representation (`1005.2500001`), which keeps extra decimals visible to the validator.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningWorkbookTest.php && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS. If the arch test complains, run `php artisan test --compact tests/Arch` — the reader must contain no `float` token.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Go-live import: template and workbook reader"
```

---

### Task 3: Validator and typed rows

**Files:**
- Create: `app/Domain/GoLive/Data/OpeningMemberRow.php`, `OpeningSocietyRow.php`, `OpeningInvestmentRow.php`, `OpeningImportError.php`, `ValidatedOpening.php`, `app/Domain/GoLive/Services/OpeningImportValidator.php`
- Modify: `lang/{bn,en}/golive.php` (`errors.*`, `sheet.*`)
- Test: `tests/Feature/GoLive/OpeningImportValidatorTest.php`

**Interfaces:**
- Consumes: `OpeningWorkbook` (Task 2), `MemberData`, `NomineeData::fromForm`, `MemberRules::assertValid`, `MobileNumber::normalize`, `Money::tryOfTaka`, `Bps::ofPercent`, `InvestmentType`, `PaymentMethod`, `YearMonth`.
- Produces:
  - `OpeningMemberRow(int $row, string $memberNo, MemberData $member, int $shares, Money $savings, Money $advance, Money $arrearsDeposit, Money $arrearsFees)`
  - `OpeningSocietyRow(int $row, string $accountCode, Money $amount)`
  - `OpeningInvestmentRow(int $row, InvestmentType $type, string $institution, ?string $instrumentNo, Money $principal, CarbonImmutable $investedOn, ?CarbonImmutable $maturesOn, ?Bps $expectedRate, PaymentMethod $fundedFrom)`
  - `OpeningImportError(string $sheet, int $row, ?string $column, string $key, array $replace = [])` with `message(): string` and `toArray(): array{sheet: string, row: int, column: string|null, key: string, replace: array<string, string>}`
  - `ValidatedOpening(list<OpeningMemberRow> $members, list<OpeningSocietyRow> $society, list<OpeningInvestmentRow> $investments, list<OpeningImportError> $errors)` with `isClean(): bool`
  - `OpeningImportValidator::validate(OpeningWorkbook $book, YearMonth $goLive): ValidatedOpening`
  - `OpeningImportValidator::DEBIT_CODES = ['1101', '1111', '1121', '1122']`, `CREDIT_CODES = ['2211', '3101', '3201', '3202', '3203']`

Rows with an error are left out of the typed lists; the error list explains why.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Support\Time\YearMonth;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function memberRow(array $overrides = []): array
{
    static $n = 0;
    $n++;

    return [
        '_row' => (string) ($n + 2), 'member_no' => sprintf('M-%04d', $n), 'name_bn' => 'রহিম', 'name_en' => 'Rahim',
        'mobile' => sprintf('0171%07d', $n), 'nid' => '', 'joined_on' => '2020-07-01', 'shares' => '2',
        'savings' => '12000', 'advance' => '', 'arrears_deposit' => '', 'arrears_fees' => '',
        'nominee1_name' => '', 'nominee1_relation' => '', 'nominee1_percent' => '', 'nominee2_name' => '', 'nominee2_relation' => '', 'nominee2_percent' => '',
        ...$overrides,
    ];
}

function validateOpening(array $members = [], array $society = [], array $investments = []): \App\Domain\GoLive\Data\ValidatedOpening
{
    return app(OpeningImportValidator::class)->validate(new OpeningWorkbook($members ?: [memberRow()], $society, $investments), YearMonth::parse('2026-07'));
}

function errorKeys(\App\Domain\GoLive\Data\ValidatedOpening $result): array
{
    return array_map(fn ($error) => $error->key, $result->errors);
}

it('turns a clean sheet into typed rows, reading Bangla digits and Excel-dropped zeros', function (): void {
    $result = validateOpening([memberRow([
        'mobile' => '1712345678', 'shares' => '৩', 'savings' => '১,০০৫.২৫', 'joined_on' => '২০২০-০৭-০১',
        'nominee1_name' => 'Karima', 'nominee1_relation' => 'Wife', 'nominee1_percent' => '100',
    ])], [['_row' => '3', 'account_code' => '1101', 'amount' => '5000.50']], [[
        '_row' => '3', 'type' => 'fixed_deposit', 'institution' => 'Sonali Bank', 'instrument_no' => 'FDR-1', 'principal' => '100000',
        'invested_on' => '2025-01-10', 'matures_on' => '2027-01-10', 'expected_rate_percent' => '8.5', 'funded_from' => 'bank',
    ]]);

    expect($result->isClean())->toBeTrue()
        ->and($result->members[0]->member->mobile)->toBe('01712345678')
        ->and($result->members[0]->shares)->toBe(3)
        ->and($result->members[0]->savings->poisha)->toBe(100525)
        ->and($result->members[0]->member->nominees[0]->share->value)->toBe(10000)
        ->and($result->society[0]->amount->poisha)->toBe(500050)
        ->and($result->investments[0]->expectedRate?->value)->toBe(850);
});

it('reports each problem with its sheet, row and column', function (array $overrides, string $key): void {
    $result = validateOpening([memberRow($overrides)]);

    expect(errorKeys($result))->toContain($key)
        ->and($result->members)->toBe([]);
})->with([
    'member no' => [['member_no' => '42'], 'golive.errors.member_no_format'],
    'joined after go-live' => [['joined_on' => '2026-07-01'], 'golive.errors.joined_after_go_live'],
    'bad date' => [['joined_on' => '01/07/2020'], 'golive.errors.date_format'],
    'shares' => [['shares' => '0'], 'golive.errors.shares_positive'],
    'three decimals' => [['savings' => '1005.2500001'], 'golive.errors.amount_invalid'],
    'negative' => [['savings' => '-5'], 'golive.errors.amount_invalid'],
    'advance and arrears' => [['advance' => '500', 'arrears_deposit' => '500'], 'golive.errors.advance_and_arrears'],
    'mobile' => [['mobile' => '12345'], 'members.errors.mobile_format'],
    'nominee total' => [['nominee1_name' => 'A', 'nominee1_relation' => 'Son', 'nominee1_percent' => '60'], 'members.errors.nominee_total'],
]);

it('finds duplicates inside the sheet', function (): void {
    $result = validateOpening([memberRow(['member_no' => 'M-0100', 'mobile' => '01811111111']), memberRow(['member_no' => 'M-0100', 'mobile' => '01811111111'])]);

    expect(errorKeys($result))->toContain('golive.errors.duplicate_member_no', 'golive.errors.duplicate_mobile');
});

it('checks the society and investment sheets', function (): void {
    $result = validateOpening(society: [
        ['_row' => '3', 'account_code' => '2101', 'amount' => '5'],
        ['_row' => '4', 'account_code' => '1101', 'amount' => '5'],
        ['_row' => '5', 'account_code' => '1101', 'amount' => '6'],
    ], investments: [
        ['_row' => '3', 'type' => 'gold', 'institution' => 'X', 'instrument_no' => '', 'principal' => '0', 'invested_on' => '2026-08-01', 'matures_on' => '', 'expected_rate_percent' => '', 'funded_from' => 'cheque'],
    ]);

    expect(errorKeys($result))->toContain(
        'golive.errors.account_not_allowed', 'golive.errors.duplicate_account', 'golive.errors.investment_type',
        'golive.errors.institution_required', 'golive.errors.amount_positive', 'golive.errors.invested_after_go_live', 'golive.errors.funded_from',
    );
});

it('needs at least one member', function (): void {
    expect(errorKeys(app(OpeningImportValidator::class)->validate(new OpeningWorkbook([], [], []), YearMonth::parse('2026-07'))))
        ->toContain('golive.errors.no_members');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningImportValidatorTest.php`
Expected: FAIL — `OpeningImportValidator` not found.

- [ ] **Step 3: DTOs**

```php
<?php
// app/Domain/GoLive/Data/OpeningImportError.php
declare(strict_types=1);

namespace App\Domain\GoLive\Data;

final readonly class OpeningImportError
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        public string $sheet,
        public int $row,
        public ?string $column,
        public string $key,
        public array $replace = [],
    ) {}

    public function message(): string
    {
        return __($this->key, $this->replace);
    }

    /**
     * @return array{sheet: string, row: int, column: string|null, key: string, replace: array<string, string>}
     */
    public function toArray(): array
    {
        return ['sheet' => $this->sheet, 'row' => $this->row, 'column' => $this->column, 'key' => $this->key, 'replace' => $this->replace];
    }
}
```

```php
<?php
// app/Domain/GoLive/Data/OpeningMemberRow.php
declare(strict_types=1);

namespace App\Domain\GoLive\Data;

use App\Domain\Members\Data\MemberData;
use App\Support\Money\Money;

final readonly class OpeningMemberRow
{
    public function __construct(
        public int $row,
        public string $memberNo,
        public MemberData $member,
        public int $shares,
        public Money $savings,
        public Money $advance,
        public Money $arrearsDeposit,
        public Money $arrearsFees,
    ) {}
}
```

```php
<?php
// app/Domain/GoLive/Data/OpeningSocietyRow.php
declare(strict_types=1);

namespace App\Domain\GoLive\Data;

use App\Support\Money\Money;

final readonly class OpeningSocietyRow
{
    public function __construct(public int $row, public string $accountCode, public Money $amount) {}
}
```

```php
<?php
// app/Domain/GoLive/Data/OpeningInvestmentRow.php
declare(strict_types=1);

namespace App\Domain\GoLive\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Enums\InvestmentType;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class OpeningInvestmentRow
{
    public function __construct(
        public int $row,
        public InvestmentType $type,
        public string $institution,
        public ?string $instrumentNo,
        public Money $principal,
        public CarbonImmutable $investedOn,
        public ?CarbonImmutable $maturesOn,
        public ?Bps $expectedRate,
        public PaymentMethod $fundedFrom,
    ) {}
}
```

```php
<?php
// app/Domain/GoLive/Data/ValidatedOpening.php
declare(strict_types=1);

namespace App\Domain\GoLive\Data;

final readonly class ValidatedOpening
{
    /**
     * @param  list<OpeningMemberRow>  $members
     * @param  list<OpeningSocietyRow>  $society
     * @param  list<OpeningInvestmentRow>  $investments
     * @param  list<OpeningImportError>  $errors
     */
    public function __construct(
        public array $members,
        public array $society,
        public array $investments,
        public array $errors,
    ) {}

    public function isClean(): bool
    {
        return $this->errors === [];
    }
}
```

- [ ] **Step 4: Validator**

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\GoLive\Data\OpeningImportError;
use App\Domain\GoLive\Data\OpeningInvestmentRow;
use App\Domain\GoLive\Data\OpeningMemberRow;
use App\Domain\GoLive\Data\OpeningSocietyRow;
use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Data\ValidatedOpening;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Services\MemberRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Bangla\BanglaNumber;
use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;
use App\Support\Money\InvalidMoneyAmount;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

/**
 * Checks every row of the go-live workbook and returns typed rows plus a list of errors (sheet,
 * row, column, message). Nothing is thrown for bad data, so staff see every problem at once.
 */
final class OpeningImportValidator
{
    public const array DEBIT_CODES = ['1101', '1111', '1121', '1122'];

    public const array CREDIT_CODES = ['2211', '3101', '3201', '3202', '3203'];

    /** @var list<OpeningImportError> */
    private array $errors = [];

    public function __construct(private readonly MemberRules $rules) {}

    public function validate(OpeningWorkbook $book, YearMonth $goLive): ValidatedOpening
    {
        $this->errors = [];

        if ($book->members === []) {
            $this->error('members', 0, null, 'golive.errors.no_members');
        }

        $members = $this->members($book->members, $goLive);
        $society = $this->society($book->society);
        $investments = $this->investments($book->investments, $goLive);

        return new ValidatedOpening($members, $society, $investments, $this->errors);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<OpeningMemberRow>
     */
    private function members(array $rows, YearMonth $goLive): array
    {
        $valid = [];
        $seen = ['member_no' => [], 'mobile' => [], 'nid' => []];

        foreach ($rows as $raw) {
            $row = (int) $raw['_row'];
            $before = count($this->errors);

            $memberNo = strtoupper(trim($raw['member_no']));
            if (preg_match('/^M-\d{4,}$/', $memberNo) !== 1) {
                $this->error('members', $row, 'member_no', 'golive.errors.member_no_format');
            } elseif (isset($seen['member_no'][$memberNo])) {
                $this->error('members', $row, 'member_no', 'golive.errors.duplicate_member_no', ['no' => $memberNo]);
            }
            $seen['member_no'][$memberNo] = true;

            $mobile = $this->mobile($raw['mobile']);
            if ($mobile !== '' && isset($seen['mobile'][$mobile])) {
                $this->error('members', $row, 'mobile', 'golive.errors.duplicate_mobile', ['mobile' => $mobile]);
            }
            $seen['mobile'][$mobile] = true;

            $nid = $raw['nid'] === '' ? null : BanglaNumber::toAscii(preg_replace('/\s+/', '', $raw['nid']) ?? $raw['nid']);
            if ($nid !== null && isset($seen['nid'][$nid])) {
                $this->error('members', $row, 'nid', 'golive.errors.duplicate_nid');
            }
            if ($nid !== null) {
                $seen['nid'][$nid] = true;
            }

            $joined = $this->date('members', $row, 'joined_on', $raw['joined_on'], required: true);
            if ($joined !== null && YearMonth::fromDate($joined)->isSameOrAfter($goLive)) {
                $this->error('members', $row, 'joined_on', 'golive.errors.joined_after_go_live', ['month' => (string) $goLive]);
            }

            $sharesText = BanglaNumber::toAscii($raw['shares']);
            $shares = ctype_digit($sharesText) ? (int) $sharesText : 0;
            if ($shares < 1) {
                $this->error('members', $row, 'shares', 'golive.errors.shares_positive');
            }

            $savings = $this->money('members', $row, 'savings', $raw['savings']);
            $advance = $this->money('members', $row, 'advance', $raw['advance']);
            $arrearsDeposit = $this->money('members', $row, 'arrears_deposit', $raw['arrears_deposit']);
            $arrearsFees = $this->money('members', $row, 'arrears_fees', $raw['arrears_fees']);

            if ($advance->isPositive() && ($arrearsDeposit->isPositive() || $arrearsFees->isPositive())) {
                $this->error('members', $row, 'advance', 'golive.errors.advance_and_arrears');
            }

            $nominees = $this->nominees($raw, $row);

            $data = new MemberData(
                nameBn: trim($raw['name_bn']),
                nameEn: trim($raw['name_en']),
                mobile: $mobile,
                joinedOn: $joined ?? CarbonImmutable::today(),
                nid: $nid,
                nominees: $nominees,
            );

            try {
                $this->rules->assertValid($data);
            } catch (DomainRuleViolation $violation) {
                $this->error('members', $row, null, $violation->translationKey, $this->strings($violation->replace));
            }

            if (count($this->errors) === $before) {
                $valid[] = new OpeningMemberRow($row, $memberNo, $data, $shares, $savings, $advance, $arrearsDeposit, $arrearsFees);
            }
        }

        return $valid;
    }

    /**
     * @param  array<string, string>  $raw
     * @return list<NomineeData>
     */
    private function nominees(array $raw, int $row): array
    {
        $nominees = [];

        foreach ([1, 2] as $n) {
            if (trim($raw["nominee{$n}_name"]) === '') {
                continue;
            }

            try {
                $nominees[] = NomineeData::fromForm([
                    'name' => $raw["nominee{$n}_name"],
                    'relation' => $raw["nominee{$n}_relation"],
                    'share_percent' => $raw["nominee{$n}_percent"],
                ]);
            } catch (InvalidMoneyAmount) {
                $this->error('members', $row, "nominee{$n}_percent", 'golive.errors.percent_invalid');
            }
        }

        return $nominees;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<OpeningSocietyRow>
     */
    private function society(array $rows): array
    {
        $valid = [];
        $seen = [];

        foreach ($rows as $raw) {
            $row = (int) $raw['_row'];
            $before = count($this->errors);
            $code = BanglaNumber::toAscii(trim($raw['account_code']));

            if (! in_array($code, [...self::DEBIT_CODES, ...self::CREDIT_CODES], true)) {
                $this->error('society', $row, 'account_code', 'golive.errors.account_not_allowed', ['code' => $code]);
            } elseif (isset($seen[$code])) {
                $this->error('society', $row, 'account_code', 'golive.errors.duplicate_account', ['code' => $code]);
            }
            $seen[$code] = true;

            $amount = $this->money('society', $row, 'amount', $raw['amount']);

            if (count($this->errors) === $before) {
                $valid[] = new OpeningSocietyRow($row, $code, $amount);
            }
        }

        return $valid;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<OpeningInvestmentRow>
     */
    private function investments(array $rows, YearMonth $goLive): array
    {
        $valid = [];

        foreach ($rows as $raw) {
            $row = (int) $raw['_row'];
            $before = count($this->errors);

            $type = InvestmentType::tryFrom(trim($raw['type']));
            if ($type === null) {
                $this->error('investments', $row, 'type', 'golive.errors.investment_type');
            }

            $institution = trim($raw['institution']);
            if (mb_strlen($institution) < 2) {
                $this->error('investments', $row, 'institution', 'golive.errors.institution_required');
            }

            $principal = $this->money('investments', $row, 'principal', $raw['principal']);
            if ($principal->isZero()) {
                $this->error('investments', $row, 'principal', 'golive.errors.amount_positive');
            }

            $investedOn = $this->date('investments', $row, 'invested_on', $raw['invested_on'], required: true);
            if ($investedOn !== null && ! $investedOn->isBefore($goLive->firstDay())) {
                $this->error('investments', $row, 'invested_on', 'golive.errors.invested_after_go_live');
            }

            $maturesOn = $this->date('investments', $row, 'matures_on', $raw['matures_on'], required: false);
            if ($maturesOn !== null && $investedOn !== null && ! $maturesOn->isAfter($investedOn)) {
                $this->error('investments', $row, 'matures_on', 'investments.errors.maturity');
            }

            $rate = null;
            if (trim($raw['expected_rate_percent']) !== '') {
                try {
                    $rate = Bps::ofPercent($raw['expected_rate_percent']);
                } catch (InvalidMoneyAmount) {
                    $this->error('investments', $row, 'expected_rate_percent', 'golive.errors.percent_invalid');
                }
            }

            $fundedFrom = PaymentMethod::tryFrom(trim($raw['funded_from']));
            if ($fundedFrom === null) {
                $this->error('investments', $row, 'funded_from', 'golive.errors.funded_from');
            }

            if (count($this->errors) === $before && $type !== null && $investedOn !== null && $fundedFrom !== null) {
                $instrument = trim($raw['instrument_no']);
                $valid[] = new OpeningInvestmentRow($row, $type, $institution, $instrument === '' ? null : $instrument, $principal, $investedOn, $maturesOn, $rate, $fundedFrom);
            }
        }

        return $valid;
    }

    /**
     * Blank = 0. More than 2 decimals or a negative amount is an error (never rounded).
     */
    private function money(string $sheet, int $row, string $column, string $text): Money
    {
        if (trim($text) === '') {
            return Money::zero();
        }

        $money = Money::tryOfTaka($text);

        if ($money === null || $money->isNegative()) {
            $this->error($sheet, $row, $column, 'golive.errors.amount_invalid', ['value' => $text]);

            return Money::zero();
        }

        return $money;
    }

    private function date(string $sheet, int $row, string $column, string $text, bool $required): ?CarbonImmutable
    {
        $ascii = BanglaNumber::toAscii(trim($text));

        if ($ascii === '') {
            if ($required) {
                $this->error($sheet, $row, $column, 'golive.errors.date_format');
            }

            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ascii) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $ascii, YearMonth::TIMEZONE) : null;

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $ascii) {
            $this->error($sheet, $row, $column, 'golive.errors.date_format');

            return null;
        }

        return $date;
    }

    /**
     * Excel drops a leading 0 from numbers typed into General cells (1712345678).
     */
    private function mobile(string $text): string
    {
        $digits = preg_replace('/\D+/', '', BanglaNumber::toAscii($text)) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return MobileNumber::normalize($digits) ?? trim($text);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function error(string $sheet, int $row, ?string $column, string $key, array $replace = []): void
    {
        $this->errors[] = new OpeningImportError($sheet, $row, $column, $key, $replace);
    }

    /**
     * @param  array<array-key, mixed>  $replace
     * @return array<string, string>
     */
    private function strings(array $replace): array
    {
        $out = [];

        foreach ($replace as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }
}
```

Check `InvalidMoneyAmount`'s namespace with `grep -rn "class InvalidMoneyAmount" app` and adjust the import. `MemberRules::assertValid` also checks uniqueness against the database; at go-live the members table is empty, so that only matters if someone uploads after posting, which Task 5 refuses anyway.

Add to both `lang/*/golive.php` under `errors`: `member_no_format`, `duplicate_member_no` (:no), `duplicate_mobile` (:mobile), `duplicate_nid`, `joined_after_go_live` (:month), `date_format`, `shares_positive`, `amount_invalid` (:value), `amount_positive`, `advance_and_arrears`, `percent_invalid`, `account_not_allowed` (:code), `duplicate_account` (:code), `investment_type`, `institution_required`, `invested_after_go_live`, `funded_from`, `no_members`. Example en: `'advance_and_arrears' => 'A member cannot have both an advance and arrears — enter only the difference.'`; bn: `'একজন সদস্যের অগ্রিম ও বকেয়া দুটোই থাকতে পারে না — শুধু পার্থক্যটি লিখুন।'`. Also `sheet => ['members' => …, 'society' => …, 'investments' => …]` for the error table.

- [ ] **Step 5: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningImportValidatorTest.php tests/Arch && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Go-live import: row validation with per-cell errors"
```

---

### Task 4: Opening balance sheet (summary + journal lines)

**Files:**
- Create: `app/Domain/GoLive/Services/OpeningBalanceSheet.php`
- Test: `tests/Feature/GoLive/OpeningBalanceSheetTest.php`

**Interfaces:**
- Consumes: `ValidatedOpening` (Task 3), `Accounts::byCode(string): Account`, `JournalLineData::debit/credit`, `AccountCode` constants.
- Produces:
  - `OpeningBalanceSheet::summary(ValidatedOpening $opening): array{members: int, shares: int, savings_poisha: int, advance_poisha: int, arrears_poisha: int, investments: int, surplus_poisha: int, lines: list<array{code: string, debit_poisha: int, credit_poisha: int}>}` — `lines` aggregated per account code (members' 2101/2111 summed), `surplus_poisha` positive = credit to 3901, negative = debit.
  - `OpeningBalanceSheet::journalLines(ValidatedOpening $opening, array<string, int> $memberIdsByNo): list<JournalLineData>` — one line per member per non-zero 2101/2111, one per society row, one per investment (its type's 13xx account), one 3901 line if surplus ≠ 0; zero amounts skipped.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Services\OpeningBalanceSheet;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => $this->seed(ChartOfAccountsSeeder::class));

function opening(array $members, array $society = [], array $investments = []): \App\Domain\GoLive\Data\ValidatedOpening
{
    $result = app(OpeningImportValidator::class)->validate(new OpeningWorkbook($members, $society, $investments), YearMonth::parse('2026-07'));
    expect($result->errors)->toBe([]);

    return $result;
}

it('balances with the difference in accumulated surplus', function (): void {
    $result = opening(
        [memberRow(['member_no' => 'M-0001', 'savings' => '10000', 'advance' => '1000']), memberRow(['member_no' => 'M-0002', 'savings' => '5000'])],
        [['_row' => '3', 'account_code' => '1101', 'amount' => '2000'], ['_row' => '4', 'account_code' => '3201', 'amount' => '500']],
        [['_row' => '3', 'type' => 'fixed_deposit', 'institution' => 'Sonali', 'instrument_no' => '', 'principal' => '15000', 'invested_on' => '2025-01-01', 'matures_on' => '', 'expected_rate_percent' => '', 'funded_from' => 'bank']],
    );

    $summary = app(OpeningBalanceSheet::class)->summary($result);
    $lines = app(OpeningBalanceSheet::class)->journalLines($result, ['M-0001' => 11, 'M-0002' => 12]);
    $debit = Money::sum(array_map(fn ($l) => $l->debit, $lines));
    $credit = Money::sum(array_map(fn ($l) => $l->credit, $lines));

    // assets 2,000 + 15,000 = 17,000; owed 10,000 + 1,000 + 5,000 + 500 = 16,500 → surplus 500 credit
    expect($summary['surplus_poisha'])->toBe(50000)
        ->and($debit->equals($credit))->toBeTrue()
        ->and($debit->poisha)->toBe(1700000)
        ->and(collect($lines)->where('memberId', 11)->count())->toBe(2)
        ->and($summary)->toMatchArray(['members' => 2, 'savings_poisha' => 1500000, 'advance_poisha' => 100000, 'investments' => 1]);
});

it('debits accumulated surplus when the society owes more than it holds, and omits it when equal', function (): void {
    $short = opening([memberRow(['member_no' => 'M-0001', 'savings' => '1000'])], [['_row' => '3', 'account_code' => '1101', 'amount' => '400']]);
    $even = opening([memberRow(['member_no' => 'M-0001', 'savings' => '1000'])], [['_row' => '3', 'account_code' => '1101', 'amount' => '1000']]);
    $sheet = app(OpeningBalanceSheet::class);

    expect($sheet->summary($short)['surplus_poisha'])->toBe(-60000)
        ->and(collect($sheet->journalLines($short, ['M-0001' => 1]))->last()->debit->poisha)->toBe(60000)
        ->and(collect($sheet->journalLines($even, ['M-0001' => 1])))->toHaveCount(2);
});

it('always balances for random sheets', function (): void {
    foreach (range(1, 50) as $seed) {
        mt_srand($seed);
        $members = [];
        foreach (range(1, mt_rand(1, 8)) as $i) {
            $members[] = memberRow(['member_no' => sprintf('M-%04d', $i), 'savings' => mt_rand(0, 99999).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT), 'advance' => (string) mt_rand(0, 500)]);
        }
        $result = opening($members, [['_row' => '3', 'account_code' => '1111', 'amount' => (string) mt_rand(0, 900000)]]);
        $lines = app(OpeningBalanceSheet::class)->journalLines($result, collect($members)->mapWithKeys(fn ($m, $i) => [$m['member_no'] => $i + 1])->all());

        expect(Money::sum(array_map(fn ($l) => $l->debit, $lines))->equals(Money::sum(array_map(fn ($l) => $l->credit, $lines))))->toBeTrue();
    }
});
```

`memberRow()` comes from `OpeningImportValidatorTest.php`. Pest loads every test file's global functions, but to be safe move `memberRow()` into `tests/Pest.php` in this step (and delete it from the validator test).

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningBalanceSheetTest.php`
Expected: FAIL — `OpeningBalanceSheet` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\GoLive\Data\ValidatedOpening;
use App\Support\Money\Money;

/**
 * The opening journal and its summary from validated rows. Used by both the preview and the
 * post, so the posted voucher is exactly what was previewed. The difference between what the
 * society holds and what it owes goes to Accumulated Surplus (3901).
 */
final class OpeningBalanceSheet
{
    public function __construct(private readonly Accounts $accounts) {}

    /**
     * @return array{members: int, shares: int, savings_poisha: int, advance_poisha: int, arrears_poisha: int, investments: int, surplus_poisha: int, lines: list<array{code: string, debit_poisha: int, credit_poisha: int}>}
     */
    public function summary(ValidatedOpening $opening): array
    {
        $byCode = [];

        foreach ($this->entries($opening, []) as [$code, $debit, $credit]) {
            $byCode[$code] ??= ['code' => $code, 'debit_poisha' => 0, 'credit_poisha' => 0];
            $byCode[$code]['debit_poisha'] += $debit->poisha;
            $byCode[$code]['credit_poisha'] += $credit->poisha;
        }

        ksort($byCode);
        $members = collect($opening->members);

        return [
            'members' => count($opening->members),
            'shares' => (int) $members->sum('shares'),
            'savings_poisha' => Money::sum($members->map(fn ($m) => $m->savings))->poisha,
            'advance_poisha' => Money::sum($members->map(fn ($m) => $m->advance))->poisha,
            'arrears_poisha' => Money::sum($members->map(fn ($m) => $m->arrearsDeposit->plus($m->arrearsFees)))->poisha,
            'investments' => count($opening->investments),
            'surplus_poisha' => $this->surplus($opening)->poisha,
            'lines' => array_values($byCode),
        ];
    }

    /**
     * @param  array<string, int>  $memberIdsByNo
     * @return list<JournalLineData>
     */
    public function journalLines(ValidatedOpening $opening, array $memberIdsByNo): array
    {
        $lines = [];

        foreach ($this->entries($opening, $memberIdsByNo) as [$code, $debit, $credit, $memberId, $memo]) {
            $account = $this->accounts->byCode($code);
            $lines[] = $debit->isPositive()
                ? JournalLineData::debit($account, $debit, $memberId, $memo)
                : JournalLineData::credit($account, $credit, $memberId, $memo);
        }

        return $lines;
    }

    /**
     * Positive = the society holds more than it owes (credit 3901).
     */
    public function surplus(ValidatedOpening $opening): Money
    {
        $held = Money::zero();
        $owed = Money::zero();

        foreach ($opening->society as $row) {
            if (in_array($row->accountCode, OpeningImportValidator::DEBIT_CODES, true)) {
                $held = $held->plus($row->amount);
            } else {
                $owed = $owed->plus($row->amount);
            }
        }

        foreach ($opening->investments as $row) {
            $held = $held->plus($row->principal);
        }

        foreach ($opening->members as $row) {
            $owed = $owed->plus($row->savings)->plus($row->advance);
        }

        return $held->minus($owed);
    }

    /**
     * Non-zero entries in posting order: society debits, investments, members, society credits, 3901.
     *
     * @param  array<string, int>  $memberIdsByNo
     * @return list<array{0: string, 1: Money, 2: Money, 3: int|null, 4: string|null}>
     */
    private function entries(ValidatedOpening $opening, array $memberIdsByNo): array
    {
        $zero = Money::zero();
        $entries = [];

        foreach ($opening->society as $row) {
            if ($row->amount->isPositive() && in_array($row->accountCode, OpeningImportValidator::DEBIT_CODES, true)) {
                $entries[] = [$row->accountCode, $row->amount, $zero, null, null];
            }
        }

        foreach ($opening->investments as $row) {
            $entries[] = [$row->type->accountCode(), $row->principal, $zero, null, trim($row->institution.' '.($row->instrumentNo ?? ''))];
        }

        foreach ($opening->members as $row) {
            $memberId = $memberIdsByNo[$row->memberNo] ?? null;

            if ($row->savings->isPositive()) {
                $entries[] = [AccountCode::MEMBER_SAVINGS, $zero, $row->savings, $memberId, $row->memberNo];
            }

            if ($row->advance->isPositive()) {
                $entries[] = [AccountCode::MEMBER_ADVANCE, $zero, $row->advance, $memberId, $row->memberNo];
            }
        }

        foreach ($opening->society as $row) {
            if ($row->amount->isPositive() && in_array($row->accountCode, OpeningImportValidator::CREDIT_CODES, true)) {
                $entries[] = [$row->accountCode, $zero, $row->amount, null, null];
            }
        }

        $surplus = $this->surplus($opening);

        if ($surplus->isPositive()) {
            $entries[] = [AccountCode::ACCUMULATED_SURPLUS, $zero, $surplus, null, null];
        } elseif ($surplus->isNegative()) {
            $entries[] = [AccountCode::ACCUMULATED_SURPLUS, $surplus->negated(), $zero, null, null];
        }

        return $entries;
    }
}
```

Check `app/Domain/Accounting/AccountCode.php` for the exact constant names of 2101, 2111 and 3901 (`grep -n "2101\|2111\|3901" app/Domain/Accounting/AccountCode.php`); add `ACCUMULATED_SURPLUS = '3901'` if it does not exist.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Go-live import: opening balance sheet and journal lines"
```

---

### Task 5: Upload action (draft)

**Files:**
- Create: `app/Domain/GoLive/Actions/UploadOpeningImport.php`
- Modify: `lang/{bn,en}/golive.php` (`errors.already_posted`), `lang/{bn,en}/audit.php` if the audit log lists event names (check `grep -n "backup_requested" lang/en/audit.php`; add `opening_import_uploaded` and `opening_import_posted` the same way)
- Test: `tests/Feature/GoLive/UploadOpeningImportTest.php`

**Interfaces:**
- Consumes: `OpeningWorkbookReader::read`, `OpeningImportValidator::validate`, `OpeningBalanceSheet::summary`, `OpeningImport`.
- Produces: `UploadOpeningImport::__invoke(User $actor, string $path, string $fileName, YearMonth $goLive): OpeningImport` — `$path` is relative to the `local` disk. Returns the single draft (created or replaced). Throws `DomainRuleViolation('golive.errors.already_posted')` when an import is posted.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

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

function storeOpeningFile(array $rows): string
{
    Storage::disk('local')->put('opening-imports/sheet.xlsx', (string) file_get_contents(openingWorkbookFile($rows)));

    return 'opening-imports/sheet.xlsx';
}

it('saves a draft with errors and a summary, and replaces it on re-upload', function (): void {
    $upload = app(UploadOpeningImport::class);

    $first = $upload($this->admin, storeOpeningFile(['members' => [memberRow(['member_no' => 'bad'])]]), 'sheet.xlsx', YearMonth::parse('2026-07'));
    expect($first->status)->toBe(OpeningImportStatus::Draft)
        ->and($first->errors)->not->toBe([]);

    $second = $upload($this->admin, storeOpeningFile(['members' => [memberRow(['member_no' => 'M-0001', 'savings' => '100'])]]), 'sheet.xlsx', YearMonth::parse('2026-07'));
    expect(OpeningImport::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->errors)->toBe([])
        ->and($second->summary['savings_poisha'])->toBe(10000)
        ->and($second->payload['members'][0]['member_no'])->toBe('M-0001')
        ->and(\App\Domain\Audit\Models\AuditEntry::query()->where('event', 'opening_import_uploaded')->count())->toBe(2);
});

it('refuses uploads after posting and from users without the permission', function (): void {
    expect(fn () => app(UploadOpeningImport::class)(userWithRole(Role::Accountant), storeOpeningFile([]), 'x.xlsx', YearMonth::parse('2026-07')))
        ->toThrow(AuthorizationException::class);

    OpeningImport::query()->create(['status' => OpeningImportStatus::Posted, 'go_live_month' => YearMonth::parse('2026-07'), 'file_path' => 'p', 'file_name' => 'p', 'file_sha256' => str_repeat('b', 64), 'payload' => [], 'errors' => [], 'summary' => [], 'uploaded_by' => $this->admin->id]);

    expect(fn () => app(UploadOpeningImport::class)($this->admin, storeOpeningFile([]), 'x.xlsx', YearMonth::parse('2026-07')))
        ->toThrow(DomainRuleViolation::class, __('golive.errors.already_posted'));
});
```

Move `openingWorkbookFile()` from Task 2's test into `tests/Pest.php` alongside `memberRow()`.

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/UploadOpeningImportTest.php`
Expected: FAIL — `UploadOpeningImport` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Actions;

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\GoLive\Services\OpeningBalanceSheet;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Domain\GoLive\Services\OpeningWorkbookReader;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Reads and checks the go-live workbook and keeps it as the one draft (a new upload replaces it).
 * A draft changes no money; posting is a separate, confirmed step.
 */
final class UploadOpeningImport
{
    public function __construct(
        private readonly OpeningWorkbookReader $reader,
        private readonly OpeningImportValidator $validator,
        private readonly OpeningBalanceSheet $sheet,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, string $path, string $fileName, YearMonth $goLive): OpeningImport
    {
        Gate::forUser($actor)->authorize('create', OpeningImport::class);

        if (OpeningImport::query()->where('status', OpeningImportStatus::Posted)->exists()) {
            throw DomainRuleViolation::because('golive.errors.already_posted');
        }

        $disk = Storage::disk('local');
        $book = $this->reader->read($disk->path($path));
        $validated = $this->validator->validate($book, $goLive);
        $hash = hash('sha256', (string) $disk->get($path));

        return $this->causer->withCauser($actor, fn (): OpeningImport => DB::transaction(function () use ($actor, $path, $fileName, $goLive, $book, $validated, $hash): OpeningImport {
            $draft = OpeningImport::query()->where('status', OpeningImportStatus::Draft)->lockForUpdate()->first() ?? new OpeningImport(['status' => OpeningImportStatus::Draft]);
            $oldPath = $draft->exists ? $draft->file_path : null;

            $draft->fill([
                'go_live_month' => $goLive,
                'file_path' => $path,
                'file_name' => $fileName,
                'file_sha256' => $hash,
                'payload' => $book->toArray(),
                'errors' => array_map(fn ($error) => $error->toArray(), $validated->errors),
                'summary' => $this->sheet->summary($validated),
                'uploaded_by' => $actor->id,
            ])->save();

            if ($oldPath !== null && $oldPath !== $path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
            }

            activity('golive')
                ->performedOn($draft)
                ->event('opening_import_uploaded')
                ->withProperties(['file' => $fileName, 'go_live' => (string) $goLive, 'errors' => count($validated->errors), 'members' => count($validated->members)])
                ->log('opening import uploaded');

            return $draft;
        }, attempts: 3));
    }
}
```

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Go-live import: upload keeps one checked draft"
```

---

### Task 6: Engine guards for opening data

**Files:**
- Create: `app/Domain/GoLive/Services/GoLiveMonth.php`
- Modify: `app/Domain/Members/Services/ShareChanger.php` (add `openingLot()`), `app/Domain/Contributions/Actions/GenerateMonthlyDues.php:assertMonthAllowed`, `app/Domain/Contributions/Actions/ApplyLateFees.php` (dues query + `candidateMembers`), `app/Domain/Integrity/Checks/InvestmentPostings.php`, `lang/{bn,en}/dues.php` (`errors.before_go_live`)
- Test: `tests/Feature/GoLive/OpeningEngineGuardsTest.php`

**Interfaces:**
- Consumes: `OpeningImport` (Task 1).
- Produces:
  - `GoLiveMonth::get(): ?YearMonth` — the posted import's month, or null.
  - `ShareChanger::openingLot(User $actor, Member $member, int $shares, YearMonth $from): ShareLot` — creates the lot and share history, **no** rate-plan lookup and **no** registration fee.
  - `GenerateMonthlyDues` throws `DomainRuleViolation('dues.errors.before_go_live', ['month' => …, 'go_live' => …])` for months before go-live.
  - `ApplyLateFees` ignores dues with `opening = true`.
  - `InvestmentPostings` compares register totals per (voucher, 13xx account) with that voucher's movement on the account.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Integrity\Checks\InvestmentPostings;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('opens a share lot in a month with no rate plan and charges no registration fee', function (): void {
    $member = Member::query()->create([...memberData()->toAttributes(), 'member_no' => 'M-0001', 'status' => 'active', 'created_by' => $this->admin->id]);

    $lot = app(ShareChanger::class)->openingLot($this->admin, $member, 3, YearMonth::parse('2019-01'));

    expect($lot->shares)->toBe(3)
        ->and($member->sharesIn(YearMonth::parse('2026-07')))->toBe(3)
        ->and(Due::query()->count())->toBe(0);
});

it('refuses to generate dues for a month before go-live', function (): void {
    CarbonImmutable::setTestNow('2026-07-05');
    approvedPlan('2026-06', '500');
    OpeningImport::query()->create(['status' => OpeningImportStatus::Posted, 'go_live_month' => YearMonth::parse('2026-07'), 'file_path' => 'p', 'file_name' => 'p', 'file_sha256' => str_repeat('c', 64), 'payload' => [], 'errors' => [], 'summary' => [], 'uploaded_by' => $this->admin->id]);

    expect(fn () => app(GenerateMonthlyDues::class)(YearMonth::parse('2026-06')))
        ->toThrow(DomainRuleViolation::class, __('dues.errors.before_go_live', ['month' => '2026-06', 'go_live' => '2026-07']));
});

it('never charges a late fee on opening arrears', function (): void {
    CarbonImmutable::setTestNow('2026-09-20');
    $plan = approvedPlan('2026-07', '500');
    $member = Member::query()->create([...memberData()->toAttributes(), 'member_no' => 'M-0001', 'status' => 'active', 'created_by' => $this->admin->id]);
    $lot = app(ShareChanger::class)->openingLot($this->admin, $member, 1, YearMonth::parse('2020-01'));
    Due::query()->create([
        'member_id' => $member->id, 'month' => YearMonth::parse('2026-06'), 'type' => DueType::Deposit, 'share_lot_id' => $lot->id,
        'adjustment_seq' => 0, 'rate_plan_id' => $plan->id, 'snapshot' => $plan->snapshot(), 'amount_poisha' => Money::ofTaka('1500'),
        'paid_poisha' => Money::zero(), 'due_date' => '2026-06-30', 'status' => DueStatus::Open, 'opening' => true,
    ]);

    expect(app(ApplyLateFees::class)()['count'])->toBe(0);
});

it('keeps the investment check clean when one voucher opens two investments on the same account', function (): void {
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    $investments = collect([100000, 50000])->map(fn (int $taka, int $i) => Investment::query()->create([
        'investment_no' => sprintf('INV-%04d', $i + 1), 'type' => InvestmentType::FixedDeposit, 'account_id' => account('1301')->id,
        'institution' => 'Bank '.$i, 'principal_poisha' => Money::ofTaka((string) $taka), 'funded_from' => 'bank', 'invested_on' => '2025-01-01',
        'status' => InvestmentStatus::Active, 'idempotency_key' => (string) Str::uuid(), 'recorded_by' => $this->admin->id,
    ]));
    $entry = app(PostJournal::class)($this->admin, new JournalEntryData(VoucherType::Journal, CarbonImmutable::parse('2026-07-01'), 'Opening balances', [
        JournalLineData::debit(account('1301'), Money::ofTaka('100000')),
        JournalLineData::debit(account('1301'), Money::ofTaka('50000')),
        JournalLineData::credit(account('3901'), Money::ofTaka('150000')),
    ]));
    foreach ($investments as $investment) {
        $investment->ledger()->create(['kind' => InvestmentEntryKind::Opening, 'delta_poisha' => $investment->principal_poisha, 'journal_entry_id' => $entry->id, 'created_by' => $this->admin->id]);
    }

    expect(app(InvestmentPostings::class)->run())->toBe([]);
});
```

Before writing, confirm the `Member` status column value (`grep -n "case Active" app/Domain/Members/Enums/MemberStatus.php`) and use `MemberStatus::Active` instead of `'active'` if the cast requires the enum.

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/OpeningEngineGuardsTest.php`
Expected: 4 FAIL — `openingLot` undefined; generation not refused; one late fee charged; investment check reports 2 findings.

- [ ] **Step 3: Implement**

`app/Domain/GoLive/Services/GoLiveMonth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Support\Time\YearMonth;

/**
 * The month the society started using the app (from the posted go-live import), or null.
 */
final class GoLiveMonth
{
    public function get(): ?YearMonth
    {
        return OpeningImport::query()->where('status', OpeningImportStatus::Posted)->first()?->go_live_month;
    }
}
```

In `ShareChanger` add (beside `increase`):

```php
    /**
     * A lot brought in by the go-live import: effective from the member's original month, with no
     * rate-plan lookup and no registration fee (paid before the app existed).
     */
    public function openingLot(User $actor, Member $member, int $shares, YearMonth $from): ShareLot
    {
        $this->assertCount($shares);

        $lot = ShareLot::query()->create([
            'member_id' => $member->id,
            'shares' => $shares,
            'effective_from' => $from,
            'created_by' => $actor->id,
        ]);

        $this->record($actor, $member, ShareChangeType::Increase, $shares, $from, 'Opening balance');

        return $lot;
    }
```

In `GenerateMonthlyDues`, inject `private readonly GoLiveMonth $goLive` in the constructor and add at the top of `assertMonthAllowed()`:

```php
        $goLive = $this->goLive->get();

        if ($goLive !== null && $month->isBefore($goLive)) {
            throw DomainRuleViolation::because('dues.errors.before_go_live', ['month' => (string) $month, 'go_live' => (string) $goLive]);
        }
```

Lang: en `'before_go_live' => 'Dues before the go-live month (:go_live) are not generated; :month is covered by the opening balances.'`; bn `'চালুর মাসের (:go_live) আগের বকেয়া তৈরি হয় না; :month মাস প্রারম্ভিক জেরে ধরা আছে।'`.

In `ApplyLateFees`, add `->where('opening', false)` to the `$dues` query inside the transaction and `->where('d.opening', false)` to `candidateMembers()`.

Replace the first query in `InvestmentPostings::run()` with a per-voucher-and-account comparison:

```php
        $mismatches = DB::select(<<<'SQL'
            WITH register AS (
                SELECT le.journal_entry_id, i.account_id, SUM(le.delta_poisha) AS delta, MIN(i.investment_no) AS investment_no, MIN(le.id) AS id
                FROM investment_ledger_entries le
                JOIN investments i ON i.id = le.investment_id
                GROUP BY le.journal_entry_id, i.account_id
            )
            SELECT r.investment_no, r.id, r.delta AS delta_poisha,
                   COALESCE((SELECT SUM(l.debit_poisha - l.credit_poisha) FROM journal_lines l
                             WHERE l.journal_entry_id = r.journal_entry_id AND l.account_id = r.account_id), 0) AS posted
            FROM register r
            WHERE r.delta <> COALESCE((SELECT SUM(l.debit_poisha - l.credit_poisha) FROM journal_lines l
                                       WHERE l.journal_entry_id = r.journal_entry_id AND l.account_id = r.account_id), 0)
            SQL);
```

Update the class docblock: "Every voucher's investment register entries add up to that voucher's net movement on each 13xx account …".

- [ ] **Step 4: Run tests (new + existing affected), format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive tests/Feature/Investments tests/Feature/Integrity tests/Feature/Contributions && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Go-live guards: opening share lots, no dues before go-live, no late fee on opening arrears"
```

---

### Task 7: Post the opening balances

**Files:**
- Create: `app/Domain/GoLive/Actions/PostOpeningImport.php`
- Modify: `lang/{bn,en}/golive.php` (`errors.not_clean`, `errors.file_changed`, `errors.not_fresh`, `errors.not_draft`, `narration`)
- Test: `tests/Feature/GoLive/PostOpeningImportTest.php`

**Interfaces:**
- Consumes: everything above; `PostJournal`, `NomineeWriter::replace`, `PortalAccounts::forMember`, `AdvanceLedger::append`, `RateResolver::for` (throws `NoRatePlanForMonth`), `ShareChanger::openingLot`.
- Produces: `PostOpeningImport::__invoke(User $actor, OpeningImport $import): OpeningImport` (status Posted, `journal_entry_id` set).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\TrialBalance;
use App\Domain\Contributions\Actions\ApplyAdvance;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\GoLive\Actions\PostOpeningImport;
use App\Domain\GoLive\Actions\UploadOpeningImport;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\Integrity\Services\IntegrityRunner;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-01 10:00');
    $this->seed(ChartOfAccountsSeeder::class);
    Storage::fake('local');
    $this->admin = userWithRole(Role::SuperAdmin);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function goLiveDraft(array $rows): \App\Domain\GoLive\Models\OpeningImport
{
    return app(UploadOpeningImport::class)(test()->admin, storeOpeningFile($rows), 'sheet.xlsx', YearMonth::parse('2026-07'));
}

function goLiveSheet(): array
{
    return [
        'members' => [
            memberRow(['member_no' => 'M-0042', 'shares' => '2', 'joined_on' => '2019-03-15', 'savings' => '30000', 'advance' => '2500', 'nominee1_name' => 'Karima', 'nominee1_relation' => 'Wife', 'nominee1_percent' => '100']),
            memberRow(['member_no' => 'M-0007', 'shares' => '1', 'joined_on' => '2021-01-01', 'savings' => '12000', 'arrears_deposit' => '1500', 'arrears_fees' => '60']),
        ],
        'society' => [['account_code' => '1101', 'amount' => '4000'], ['account_code' => '1111', 'amount' => '20000'], ['account_code' => '3201', 'amount' => '3000']],
        'investments' => [['type' => 'fixed_deposit', 'institution' => 'Sonali Bank', 'instrument_no' => 'FDR-9', 'principal' => '25000', 'invested_on' => '2025-01-10', 'matures_on' => '2027-01-10', 'expected_rate_percent' => '8', 'funded_from' => 'bank']],
    ];
}

it('posts members, shares, arrears, advance, investments and one balanced opening voucher', function (): void {
    $import = app(PostOpeningImport::class)($this->admin, goLiveDraft(goLiveSheet()));
    $rahim = Member::query()->where('member_no', 'M-0042')->sole();
    $karim = Member::query()->where('member_no', 'M-0007')->sole();
    $entry = JournalEntry::query()->findOrFail($import->journal_entry_id);

    expect($import->status)->toBe(OpeningImportStatus::Posted)
        ->and($rahim->sharesIn(YearMonth::parse('2019-03')))->toBe(2)
        ->and($rahim->nominees()->count())->toBe(1)
        ->and(Due::query()->where('type', 'registration')->count())->toBe(0)
        ->and(app(AdvanceLedger::class)->balance($rahim->id)->poisha)->toBe(250000)
        ->and(Due::query()->where('member_id', $karim->id)->where('opening', true)->pluck('amount_poisha')->map->poisha->sort()->values()->all())->toBe([6000, 150000])
        ->and(Due::query()->where('opening', true)->first()->month->equals(YearMonth::parse('2026-06')))->toBeTrue()
        ->and(Investment::query()->sole()->status->value)->toBe('active')
        ->and($entry->entry_date->toDateString())->toBe('2026-07-01')
        ->and($entry->lines->sum(fn ($l) => $l->debit_poisha->poisha))->toBe($entry->lines->sum(fn ($l) => $l->credit_poisha->poisha))
        // held 4,000 + 20,000 + 25,000 = 49,000; owed 30,000 + 2,500 + 12,000 + 3,000 = 47,500 → 3901 credit 1,500
        ->and($entry->lines->firstWhere(fn ($l) => $l->account->code === '3901')->credit_poisha->poisha)->toBe(150000)
        ->and(app(IntegrityRunner::class)->run())->toBe([]);

    // New members continue after the highest imported number.
    $next = app(CreateMember::class)(userWithRole(Role::Secretary), memberData(), 1, YearMonth::parse('2026-07'));
    expect($next->member_no)->toBe('M-0043');
});

it('hands over to the normal cycle: go-live dues are generated and settled from the advance', function (): void {
    app(PostOpeningImport::class)($this->admin, goLiveDraft(goLiveSheet()));
    $rahim = Member::query()->where('member_no', 'M-0042')->sole();

    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    app(ApplyAdvance::class)();

    $july = Due::query()->where('member_id', $rahim->id)->where('month', '2026-07-01')->get();

    expect($july)->not->toBeEmpty()
        ->and($july->sum(fn ($due) => $due->outstanding_poisha->poisha))->toBe(0)
        ->and(app(AdvanceLedger::class)->balance($rahim->id)->poisha)->toBe(250000 - $july->sum(fn ($due) => $due->amount_poisha->poisha));
});

it('refuses a draft with errors, a changed file, a used database, and a second post', function (): void {
    $bad = goLiveDraft(['members' => [memberRow(['member_no' => 'x'])]]);
    expect(fn () => app(PostOpeningImport::class)($this->admin, $bad))->toThrow(DomainRuleViolation::class, __('golive.errors.not_clean'));

    $draft = goLiveDraft(goLiveSheet());
    Storage::disk('local')->put($draft->file_path, 'tampered');
    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class, __('golive.errors.file_changed'));

    $draft = goLiveDraft(goLiveSheet());
    app(PostOpeningImport::class)($this->admin, $draft);
    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('refuses when members already exist, and for users without the permission', function (): void {
    $draft = goLiveDraft(goLiveSheet());

    expect(fn () => app(PostOpeningImport::class)(userWithRole(Role::Accountant), $draft))->toThrow(AuthorizationException::class);

    onboard(1, '2026-07');
    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class, __('golive.errors.not_fresh'))
        ->and(Member::query()->count())->toBe(1);
});
```

Check the real class names before running: `grep -rn "class IntegrityRunner\|class .*Integrity.*Runner" app` (use whatever runs all checks and returns findings), and the `JournalLine` relation name for `account` (`grep -n "function account" app/Domain/Accounting/Models/JournalLine.php`). Adjust the two `use` lines if they differ.

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/PostOpeningImportTest.php`
Expected: FAIL — `PostOpeningImport` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Actions;

use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\GoLive\Services\OpeningBalanceSheet;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Services\NomineeWriter;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Posts the go-live import once, in one transaction: members (with their own numbers), opening
 * share lots, nominees, portal accounts, opening arrears dues, investments, one balanced opening
 * voucher, and the advance/investment ledger entries tied to it. After this the import is closed.
 */
final class PostOpeningImport
{
    public function __construct(
        private readonly OpeningImportValidator $validator,
        private readonly OpeningBalanceSheet $sheet,
        private readonly ShareChanger $shares,
        private readonly NomineeWriter $nominees,
        private readonly PortalAccounts $portal,
        private readonly AdvanceLedger $advances,
        private readonly RateResolver $rates,
        private readonly Accounts $accounts,
        private readonly PostJournal $post,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, OpeningImport $import): OpeningImport
    {
        Gate::forUser($actor)->authorize('post', $import);

        return $this->causer->withCauser($actor, fn (): OpeningImport => DB::transaction(function () use ($actor, $import): OpeningImport {
            $locked = OpeningImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();
            $goLive = $locked->go_live_month;

            if ($locked->status !== OpeningImportStatus::Draft) {
                throw DomainRuleViolation::because('golive.errors.not_draft');
            }

            if (hash('sha256', (string) Storage::disk('local')->get($locked->file_path)) !== $locked->file_sha256) {
                throw DomainRuleViolation::because('golive.errors.file_changed');
            }

            $opening = $this->validator->validate(OpeningWorkbook::fromArray($locked->payload), $goLive);

            if (! $opening->isClean()) {
                throw DomainRuleViolation::because('golive.errors.not_clean');
            }

            $this->assertFresh();

            try {
                $plan = $this->rates->for($goLive);
            } catch (NoRatePlanForMonth) {
                throw DomainRuleViolation::because('members.errors.no_rate_plan', ['month' => (string) $goLive]);
            }

            $arrearsMonth = $goLive->previous();
            $memberIds = [];
            $highest = 0;

            foreach ($opening->members as $row) {
                $member = Member::query()->create([
                    ...$row->member->toAttributes(),
                    'member_no' => $row->memberNo,
                    'status' => MemberStatus::Active,
                    'created_by' => $actor->id,
                ]);

                $memberIds[$row->memberNo] = $member->id;
                $highest = max($highest, (int) substr($row->memberNo, 2));

                $this->nominees->replace($member, $row->member->nominees);
                $lot = $this->shares->openingLot($actor, $member, $row->shares, YearMonth::fromDate($row->member->joinedOn));
                $this->portal->forMember($member);

                foreach ([[DueType::Deposit, $row->arrearsDeposit], [DueType::LateFee, $row->arrearsFees]] as [$type, $amount]) {
                    if ($amount->isPositive()) {
                        Due::query()->create([
                            'member_id' => $member->id,
                            'month' => $arrearsMonth,
                            'type' => $type,
                            'share_lot_id' => $lot->id,
                            'adjustment_seq' => 0,
                            'rate_plan_id' => $plan->id,
                            'snapshot' => $plan->snapshot(),
                            'amount_poisha' => $amount,
                            'paid_poisha' => Money::zero(),
                            'due_date' => $arrearsMonth->lastDay()->toDateString(),
                            'status' => DueStatus::Open,
                            'opening' => true,
                            'note' => 'Opening arrears',
                        ]);
                    }
                }
            }

            DB::statement("SELECT setval('member_no_seq', GREATEST(?, (SELECT last_value FROM member_no_seq)))", [$highest]);

            $investments = [];

            foreach ($opening->investments as $row) {
                $investments[] = Investment::query()->create([
                    'investment_no' => sprintf('INV-%04d', (int) DB::scalar("SELECT nextval('investment_no_seq')")),
                    'type' => $row->type,
                    'account_id' => $this->accounts->byCode($row->type->accountCode())->id,
                    'institution' => $row->institution,
                    'instrument_no' => $row->instrumentNo,
                    'principal_poisha' => $row->principal,
                    'funded_from' => $row->fundedFrom,
                    'invested_on' => $row->investedOn->toDateString(),
                    'matures_on' => $row->maturesOn?->toDateString(),
                    'expected_rate_bps' => $row->expectedRate?->value,
                    'status' => InvestmentStatus::Active,
                    'idempotency_key' => (string) Str::uuid(),
                    'recorded_by' => $actor->id,
                    'approved_by' => $actor->id,
                    'approved_at' => CarbonImmutable::now(),
                ]);
            }

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Journal,
                entryDate: $goLive->firstDay(),
                narration: __('golive.narration', ['month' => (string) $goLive], 'en'),
                lines: $this->sheet->journalLines($opening, $memberIds),
                source: $locked,
            ));

            foreach ($opening->members as $row) {
                if ($row->advance->isPositive()) {
                    $this->advances->append($memberIds[$row->memberNo], AdvanceEntryKind::Opening, $row->advance, [
                        'journal_entry_id' => $entry->id,
                        'created_by' => $actor->id,
                    ]);
                }
            }

            foreach ($investments as $investment) {
                $investment->ledger()->create([
                    'kind' => InvestmentEntryKind::Opening,
                    'delta_poisha' => $investment->principal_poisha,
                    'journal_entry_id' => $entry->id,
                    'created_by' => $actor->id,
                ]);
            }

            $locked->update([
                'status' => OpeningImportStatus::Posted,
                'journal_entry_id' => $entry->id,
                'posted_by' => $actor->id,
                'posted_at' => CarbonImmutable::now(),
            ]);

            activity('golive')
                ->performedOn($locked)
                ->event('opening_import_posted')
                ->withProperties(['go_live' => (string) $goLive, 'members' => count($opening->members), 'voucher' => $entry->voucher_no])
                ->log('opening import posted');

            return $locked;
        }, attempts: 3));
    }

    /**
     * The import is a fresh start: nothing may exist that it would sit beside.
     */
    private function assertFresh(): void
    {
        if (Member::withTrashed()->exists() || JournalEntry::query()->exists() || Payment::query()->exists() || Due::query()->exists()) {
            throw DomainRuleViolation::because('golive.errors.not_fresh');
        }
    }
}
```

Notes for the implementer:
- `update()` on `$locked` runs while its original status is Draft, so the model guard allows it. Afterwards the row is immutable.
- If `Investment` has no `approved_by`/`approved_at` columns, drop those two keys (`grep -n "approved_by" database/migrations/*investment*`).
- Lang: en `'narration' => 'Opening balances at go-live (:month)'`, plus `errors.not_clean` ("Fix every error in the sheet before posting."), `errors.file_changed` ("The uploaded file has changed since it was checked — upload it again."), `errors.not_fresh` ("Opening balances can only be posted into an empty system."), `errors.not_draft` ("This import has already been posted."); bn equivalents.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Go-live import: post the opening position in one transaction"
```

---

### Task 8: Go-live import page

**Files:**
- Create: `app/Filament/Clusters/Settings/Pages/GoLiveImportPage.php`, `resources/views/filament/clusters/settings/go-live-import.blade.php`
- Modify: `tests/Feature/ActionInventoryTest.php` (`expectedTiers()`), `lang/{bn,en}/golive.php` (page strings)
- Test: `tests/Feature/GoLive/GoLiveImportPageTest.php`

**Interfaces:**
- Consumes: `UploadOpeningImport`, `PostOpeningImport`, `OpeningTemplate::binary()`, `OpeningImport`, `OpeningImportError::message()` (rebuild from stored arrays with `__($e['key'], $e['replace'])`).
- Produces: page slug `go-live-import`; header actions `downloadTemplate` (no confirmation), `uploadOpening` (T1, FileUpload to `local` disk `opening-imports/`, plus a month picker for go-live), `post` (T3, expected text `POST`, visible only when a clean draft exists). After posting, only a summary and a link to the voucher show.

- [ ] **Step 1: Write the failing test**

```php
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
        ->assertSee(__('golive.errors.member_no_format', [], 'bn'))
        ->assertActionHidden('post')
        ->callAction('uploadOpening', data: ['file' => $good, 'go_live_month' => '2026-07'])
        ->assertActionVisible('post')
        ->callAction('post', data: ['confirm_text' => 'POST'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('golive.posted', [], 'bn'))
        ->assertActionHidden('uploadOpening');

    expect(OpeningImport::query()->sole()->status)->toBe(OpeningImportStatus::Posted);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/GoLive/GoLiveImportPageTest.php`
Expected: FAIL — `GoLiveImportPage` not found.

- [ ] **Step 3: Implement the page**

Follow `app/Filament/Clusters/Settings/Pages/BackupsPage.php` for structure (cluster, slug, `canAccess`, `getHeaderActions`, `ConfirmsWithTier`, `DomainActionRunner`).

```php
<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\GoLive\Actions\PostOpeningImport;
use App\Domain\GoLive\Actions\UploadOpeningImport;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\GoLive\Services\OpeningTemplate;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Go-live: download the template, upload it until it has no errors, then post it once.
 */
final class GoLiveImportPage extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.clusters.settings.go-live-import';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'go-live-import';

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    public static function getNavigationLabel(): string
    {
        return __('golive.title');
    }

    public function getTitle(): string
    {
        return __('golive.title');
    }

    public function getSubheading(): string
    {
        return __('golive.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('create', OpeningImport::class);
    }

    public function current(): ?OpeningImport
    {
        return OpeningImport::query()->orderByDesc('status')->latest('id')->first();
    }

    protected function getViewData(): array
    {
        $import = $this->current();

        return [
            'import' => $import,
            'errors' => array_map(fn (array $e): array => [...$e, 'message' => __($e['key'], $e['replace'])], $import?->errors ?? []),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [$this->downloadTemplateAction(), $this->uploadOpeningAction(), $this->postAction()];
    }

    public function downloadTemplateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->label(__('golive.download_template'))
            ->tooltip(__('golive.download_template'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (): StreamedResponse => response()->streamDownload(
                fn () => print (app(OpeningTemplate::class)->binary()),
                'somiti-go-live-template.xlsx',
            ));
    }

    public function uploadOpeningAction(): Action
    {
        return self::tier1(
            Action::make('uploadOpening')
                ->label(__('golive.upload'))
                ->tooltip(__('golive.upload'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('primary')
                ->visible(fn (): bool => $this->current()?->status !== OpeningImportStatus::Posted)
                ->schema([
                    TextInput::make('go_live_month')->label(__('golive.go_live_month'))->placeholder('2026-07')->regex('/^\d{4}-\d{2}$/')->required(),
                    FileUpload::make('file')->label(__('golive.file'))->disk('local')->directory('opening-imports')->visibility('private')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->maxSize(5120)->storeFileNamesIn('file_name')->required(),
                ])
                ->action(function (array $data): void {
                    $import = DomainActionRunner::run(fn (User $actor): OpeningImport => app(UploadOpeningImport::class)(
                        $actor, (string) $data['file'], (string) ($data['file_name'] ?? 'sheet.xlsx'), YearMonth::parse((string) $data['go_live_month']),
                    ));
                    Notification::make()->title($import->errors === [] ? __('golive.checked_clean') : __('golive.checked_errors', ['count' => count($import->errors)]))
                        ->status($import->errors === [] ? 'success' : 'warning')->send();
                }),
            heading: __('golive.upload'),
            description: __('golive.upload_help'),
        );
    }

    public function postAction(): Action
    {
        return self::tier3(
            Action::make('post')
                ->label(__('golive.post'))
                ->tooltip(__('golive.post'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => ($import = $this->current()) !== null && $import->status === OpeningImportStatus::Draft && $import->errors === [])
                ->action(function (): void {
                    DomainActionRunner::run(fn (User $actor): OpeningImport => app(PostOpeningImport::class)($actor, $this->current() ?? throw new \LogicException('no draft')));
                    Notification::make()->title(__('golive.posted'))->success()->send();
                }),
            heading: __('golive.post_heading'),
            expected: 'POST',
            submitLabel: __('golive.post'),
            description: fn (): string => __('golive.post_warning', [
                'members' => $this->current()?->summary['members'] ?? 0,
                'month' => (string) $this->current()?->go_live_month,
            ]),
        );
    }
}
```

Check the exact `tier1()` signature in `app/Filament/Concerns/ConfirmsWithTier.php` (`grep -n "function tier1" -A6`) and match its named arguments; check the icon/color that `ActionInventoryTest` expects for a `post` action (`grep -n "'post'" tests/Feature/ActionInventoryTest.php`) and use those.

The view `go-live-import.blade.php` renders, following `resources/views/filament/clusters/settings/backups.blade.php`:
1. Status badge (`$import->status`), go-live month, file name, uploaded by/at.
2. When `errors` is non-empty: a table with columns sheet (`__('golive.sheet.'.$e['sheet'])`), row, column, message.
3. When clean: the summary — members, shares, Σ savings, Σ advance, Σ arrears, investments (use `App\Filament\Support\Display::money()` and `Display::digits()`), and the opening trial balance table from `summary.lines` (code, debit, credit) with the 3901 line and totals.
4. When posted: posted by/at and the voucher number linking to the journal entry's view page.

- [ ] **Step 4: Register the actions in the inventory**

In `expectedTiers()` add: `'uploadOpening' => 'T1'` (in the T1 block) and `'downloadTemplate' => null` (in the no-confirmation block). `post` is already T3.

Add page strings to both lang files: `download_template`, `upload`, `upload_help`, `go_live_month`, `file`, `checked_clean`, `checked_errors` (:count), `post`, `post_heading`, `post_warning` (:members, :month — say it cannot be undone and corrections are made with normal tools), `posted`, plus view labels.

- [ ] **Step 5: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/GoLive tests/Feature/ActionInventoryTest.php tests/Arch && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Go-live import page: template, upload with errors, typed post"
```

---

### Task 9: Full gate and local-testing note

**Files:**
- Modify: `docs/LOCAL_TESTING.md` (a short "Go-live rehearsal" section: download template → fill → upload → fix → post, on a fresh `migrate:fresh --seed` database without demo members)

- [ ] **Step 1: Run the whole gate**

Run: `composer test && composer analyse && composer format:check`
Expected: all tests pass, 0 Larastan errors, Pint passed. If the demo seeder creates members, that is fine — the import refuses a non-empty database, which is the point; the rehearsal note says to seed only users, the chart of accounts and a rate plan.

- [ ] **Step 2: Commit**

```bash
git add -A && git commit -m "Go-live import: rehearsal notes"
```
