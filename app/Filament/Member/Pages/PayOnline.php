<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\Payment;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The member reports a bKash/Nagad payment (TrxID + screenshot); it waits for the accountant.
 *
 * @property-read Schema $form
 */
final class PayOnline extends Page
{
    use ConfirmsWithTier;
    use ScopedToMember;

    protected string $view = 'filament.member.pay-online';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.submit');
    }

    public function getTitle(): string
    {
        return __('portal.submit.heading');
    }

    public function mount(): void
    {
        $this->form->fill([
            'method' => PaymentMethod::Bkash->value,
            'received_on' => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Hidden::make('idempotency_key'),
                Radio::make('method')
                    ->label(__('portal.submit.method'))
                    ->options([PaymentMethod::Bkash->value => PaymentMethod::Bkash->getLabel(), PaymentMethod::Nagad->value => PaymentMethod::Nagad->getLabel()])
                    ->inline()
                    ->required()
                    ->columnSpanFull(),
                MoneyInput::make('amount')->label(__('portal.submit.amount'))->required(),
                TextInput::make('trx_id')->label(__('portal.submit.trx'))->required()->regex('/^[A-Za-z0-9]{6,40}$/')->maxLength(40),
                DatePicker::make('received_on')
                    ->label(__('portal.submit.date'))
                    ->native(false)
                    ->required(),
                FileUpload::make('proof_path')
                    ->label(__('portal.submit.proof'))
                    ->disk('local')
                    ->directory('payment-proofs')
                    ->visibility('private')
                    ->preventFilePathTampering()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize((int) config('somiti.max_proof_kb'))
                    ->required(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->submitAction()];
    }

    public function submitAction(): Action
    {
        $action = Action::make('submit')
            ->label(__('portal.submit.send'))
            ->tooltip(__('portal.submit.send'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->mountUsing(fn () => $this->form->validate())
            ->action(function (): void {
                $data = $this->form->getState();
                $member = self::member();

                $payment = DomainActionRunner::run(fn (User $actor): Payment => app(RecordPayment::class)($actor, PaymentData::fromForm([
                    ...$data,
                    'trx_id' => strtoupper((string) ($data['trx_id'] ?? '')),
                    'member_id' => $member->id,
                ])));

                Notification::make()->title(__('portal.submit.done', ['amount' => Display::money($payment->amount_poisha)]))->success()->send();
                $this->mount();
            });

        return self::tier2(
            $action,
            heading: __('portal.submit.confirm'),
            rows: fn (): array => ChangeSummary::rows(
                ['method' => __('portal.submit.method'), 'amount' => __('portal.submit.amount'), 'trx' => __('portal.submit.trx'), 'date' => __('portal.submit.date')],
                [],
                [
                    'method' => PaymentMethod::tryFrom((string) ($this->data['method'] ?? '')),
                    'amount' => '৳ '.($this->data['amount'] ?? ''),
                    'trx' => strtoupper((string) ($this->data['trx_id'] ?? '')),
                    'date' => $this->data['received_on'] ?? null,
                ],
            ),
            showOld: false,
        );
    }
}
