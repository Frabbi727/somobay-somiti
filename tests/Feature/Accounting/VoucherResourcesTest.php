<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Accounting\Models\JournalEntry;
use App\Enums\Role;
use App\Filament\Resources\JournalDrafts\Pages\CreateJournalDraft;
use App\Filament\Resources\JournalDrafts\Pages\EditJournalDraft;
use App\Filament\Resources\JournalDrafts\Pages\ListJournalDrafts;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));

    $this->accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    $this->actingAs($this->accountant);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function draftFormAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', 'content');
}

function makeDraft(User $actor, string $debitCode = '1111', string $creditCode = '1101', string $debit = '5000', string $credit = '5000'): JournalDraft
{
    return app(SaveJournalDraft::class)($actor, null, JournalDraftData::fromForm([
        'voucher_type' => 'CV',
        'entry_date' => '2026-10-03',
        'narration' => 'Cash deposited to bank',
        'lines' => [
            ['account_id' => account($debitCode)->id, 'debit' => Money::ofTaka($debit)],
            ['account_id' => account($creditCode)->id, 'credit' => Money::ofTaka($credit)],
        ],
    ]));
}

it('lists posted vouchers with amounts and hides reversal from an auditor', function (): void {
    $entry = app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '1,234.50', '2026-10-01'));
    $this->actingAs(userWithRole(Role::Auditor));

    Livewire::test(ListJournalEntries::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertSee('JV-2026-27-000001')
        ->assertSee('৳ 1,234.50')
        ->assertActionHidden(TestAction::make('reverse')->table($entry))
        ->assertActionHidden('newVoucher');
});

it('reverses a voucher only with a reason and the voucher number typed', function (): void {
    $entry = app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '100', '2026-10-01'));

    Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->callAction('reverse', data: ['reason' => '', 'confirm_text' => 'JV-2026-27-000001'])
        ->assertHasActionErrors(['reason']);

    Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->callAction('reverse', data: ['reason' => 'Posted twice', 'confirm_text' => 'JV-2026-27-000002'])
        ->assertHasActionErrors(['confirm_text']);

    expect(JournalEntry::query()->count())->toBe(1);

    Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->callAction('reverse', data: ['reason' => 'Posted twice', 'confirm_text' => 'JV-2026-27-000001'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('journal.notifications.reversed', ['voucher' => 'JV-2026-27-000001', 'reversal' => 'JV-2026-27-000002']))
        ->assertRedirect();

    expect($entry->fresh()?->isReversed())->toBeTrue();

    Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->assertActionHidden('reverse')
        ->assertSee('JV-2026-27-000002');
});

it('renders the voucher page with its lines and totals', function (): void {
    $entry = app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '1,020', '2026-10-01'));

    $this->get(route('filament.admin.resources.vouchers.view', $entry))
        ->assertOk()
        ->assertSee('১১০১')
        ->assertSee('৳ ১,০২০.০০');
});

it('prepares a voucher draft with repeater lines and shows a live balance', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateJournalDraft::class)
        ->fillForm([
            'voucher_type' => 'CV',
            'entry_date' => '2026-10-03',
            'narration' => 'Cash deposited to bank',
            'lines' => [
                ['account_id' => account('1111')->id, 'debit' => '5,000', 'credit' => null, 'memo' => 'slip 42'],
                ['account_id' => account('1101')->id, 'debit' => null, 'credit' => '4,000.50'],
            ],
        ])
        ->assertSee(__('journal.draft.difference', ['difference' => '৳ 999.50']))
        ->fillForm(['lines' => [
            ['account_id' => account('1111')->id, 'debit' => '5,000', 'credit' => null, 'memo' => 'slip 42'],
            ['account_id' => account('1101')->id, 'debit' => null, 'credit' => '5,000'],
        ]])
        ->assertSee(__('journal.draft.balanced'))
        ->callAction(draftFormAction('create'))
        ->assertHasNoFormErrors();

    $undo();

    // jsonb stores object keys in its own order, so compare as maps.
    expect(JournalDraft::query()->sole()->lines)->toEqual([
        ['account_id' => account('1111')->id, 'member_id' => null, 'debit_poisha' => 500000, 'credit_poisha' => 0, 'memo' => 'slip 42'],
        ['account_id' => account('1101')->id, 'member_id' => null, 'debit_poisha' => 0, 'credit_poisha' => 500000, 'memo' => null],
    ]);
});

it('asks for a member on member accounts', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateJournalDraft::class)
        ->fillForm([
            'voucher_type' => 'JV',
            'entry_date' => '2026-10-03',
            'narration' => 'Transfer',
            'lines' => [
                ['account_id' => account('1101')->id, 'debit' => '10'],
                ['account_id' => account('2101')->id, 'credit' => '10'],
            ],
        ])
        ->mountAction(draftFormAction('create'))
        ->assertHasFormErrors(['lines.1.member_id' => 'required']);

    $undo();

    expect(JournalDraft::query()->count())->toBe(0);
});

it('loads a saved draft back as taka text', function (): void {
    $draft = makeDraft($this->accountant, debit: '5000.25', credit: '5000.25');
    $undo = Repeater::fake();

    Livewire::test(EditJournalDraft::class, ['record' => $draft->getRouteKey()])
        ->assertSchemaStateSet([
            'lines.0.debit' => '5000.25',
            'lines.0.credit' => null,
            'lines.1.credit' => '5000.25',
        ])
        ->assertSee(__('journal.draft.balanced'));

    $undo();
});

it('posts a draft after the confirmation word is typed', function (): void {
    $draft = makeDraft($this->accountant);

    Livewire::test(EditJournalDraft::class, ['record' => $draft->getRouteKey()])
        ->callAction('post', data: ['confirm_text' => 'yes'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(EditJournalDraft::class, ['record' => $draft->getRouteKey()])
        ->callAction('post', data: ['confirm_text' => 'CONFIRM'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('journal.notifications.posted', ['voucher' => 'CV-2026-27-000001']));

    expect($draft->fresh()?->isPosted())->toBeTrue();

    Livewire::test(ListJournalDrafts::class)
        ->assertActionHidden(TestAction::make('post')->table($draft->fresh()))
        ->assertActionHidden(TestAction::make('edit')->table($draft->fresh()));
});

it('reports why an unbalanced draft cannot be posted', function (): void {
    $draft = makeDraft($this->accountant, credit: '4000');

    Livewire::test(ListJournalDrafts::class)
        ->callAction(TestAction::make('post')->table($draft), data: ['confirm_text' => 'CONFIRM'])
        ->assertNotified(__('journal.errors.unbalanced', ['debit' => '৳ 5,000.00', 'credit' => '৳ 4,000.00']));

    expect(JournalEntry::query()->count())->toBe(0)
        ->and($draft->fresh()?->isPosted())->toBeFalse();
});

it('deletes a draft with its number typed', function (): void {
    $draft = makeDraft($this->accountant);

    Livewire::test(ListJournalDrafts::class)
        ->callAction(TestAction::make('delete')->table($draft), data: ['confirm_text' => (string) $draft->id])
        ->assertHasNoActionErrors();

    expect(JournalDraft::query()->count())->toBe(0);
});
