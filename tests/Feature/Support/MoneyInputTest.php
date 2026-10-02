<?php

declare(strict_types=1);

use App\Filament\Forms\Components\MoneyInput;
use App\Support\Money\Money;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;

final class MoneyInputHarness extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?int $savedPoisha = null;

    public ?int $savedRefundPoisha = null;

    public function mount(?int $initialPoisha = null): void
    {
        $this->form->fill([
            'amount' => $initialPoisha === null ? null : Money::ofPoisha($initialPoisha),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                MoneyInput::make('amount')->required(),
                MoneyInput::make('refund')->allowNegative(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $this->savedPoisha = $state['amount']?->poisha;
        $this->savedRefundPoisha = $state['refund']?->poisha;
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

it('dehydrates typed taka into Money', function (string $typed, int $poisha): void {
    Livewire::test(MoneyInputHarness::class)
        ->fillForm(['amount' => $typed])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('savedPoisha', $poisha);
})->with([
    'grouped english' => ['1,234.50', 123450],
    'bangla digits' => ['১,২৩৪.৫০', 123450],
    'whole taka' => ['500', 50000],
]);

it('rejects malformed amounts with a translated message', function (string $typed): void {
    app()->setLocale('en');

    Livewire::test(MoneyInputHarness::class)
        ->fillForm(['amount' => $typed])
        ->call('save')
        ->assertHasFormErrors(['amount'])
        ->assertSee(__('money.validation.invalid'))
        ->assertSet('savedPoisha', null);
})->with(['12.345', 'abc', '1e3']);

it('rejects negative amounts unless allowed', function (): void {
    Livewire::test(MoneyInputHarness::class)
        ->fillForm(['amount' => '-5', 'refund' => '-5'])
        ->call('save')
        ->assertHasFormErrors(['amount'])
        ->assertHasNoFormErrors(['refund']);

    Livewire::test(MoneyInputHarness::class)
        ->fillForm(['amount' => '5', 'refund' => '-2.50'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('savedRefundPoisha', -250);
});

it('shows stored poisha as editable taka text', function (): void {
    Livewire::test(MoneyInputHarness::class, ['initialPoisha' => 123450])
        ->assertSchemaStateSet(['amount' => '1234.50']);
});
