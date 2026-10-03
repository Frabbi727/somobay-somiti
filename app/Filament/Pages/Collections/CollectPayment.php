<?php

declare(strict_types=1);

namespace App\Filament\Pages\Collections;

use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Domain\Contributions\Services\PaymentPreviewer;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Navigation\NavGroup;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * W3/W4 maker screen: pick a member, see their dues and advance, enter the money received and
 * see what it would settle before recording it for approval.
 *
 * @property-read Schema $form
 */
final class CollectPayment extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.pages.collections.collect-payment';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Collections;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'collections/collect';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('payments.collect.title');
    }

    public function getTitle(): string
    {
        return __('payments.collect.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('create', Payment::class);
    }

    public function mount(): void
    {
        $this->resetForm(request()->integer('member') ?: null);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Hidden::make('idempotency_key'),
                Select::make('member_id')
                    ->label(__('payments.field.member'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Member::query()
                        ->where('status', '!=', MemberStatus::Exited)
                        ->where(fn ($query) => $query
                            ->where('member_no', 'ilike', "%{$search}%")
                            ->orWhere('name_en', 'ilike', "%{$search}%")
                            ->orWhere('name_bn', 'ilike', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%"))
                        ->orderBy('member_no')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Member $member): array => [$member->id => $member->displayName()])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Member::query()->find((int) $value)?->displayName() : null)
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                Select::make('method')
                    ->label(__('payments.field.method'))
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Cash->value)
                    ->required()
                    ->native(false)
                    ->live(),
                MoneyInput::make('amount')
                    ->label(__('payments.field.amount'))
                    ->required()
                    ->live(onBlur: true),
                DatePicker::make('received_on')
                    ->label(__('payments.field.received_on'))
                    ->native(false)
                    ->required(),
                TextInput::make('trx_id')
                    ->label(__('payments.field.trx_id'))
                    ->helperText(__('payments.field.trx_id_help'))
                    ->regex('/^[A-Za-z0-9]{6,40}$/')
                    ->visible(fn (Get $get): bool => $this->method($get('method'))?->needsTrxId() === true)
                    ->required(fn (Get $get): bool => $this->method($get('method'))?->needsTrxId() === true),
                FileUpload::make('proof_path')
                    ->label(__('payments.field.proof'))
                    ->disk('local')
                    ->directory('payment-proofs')
                    ->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize((int) config('somiti.max_proof_kb')),
                Textarea::make('notes')->label(__('payments.field.notes'))->rows(2),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = $this->member();

        if ($member === null) {
            return ['member' => null];
        }

        $previewer = app(PaymentPreviewer::class);
        $amount = $this->amount();

        return [
            'member' => $member,
            'openDues' => $previewer->openDues($member),
            'advance' => app(AdvanceLedger::class)->balance($member->id),
            'paidThrough' => app(PaidThroughCalculator::class)->for($member),
            'preview' => $amount === null ? null : $previewer->preview($member, $amount),
        ];
    }

    protected function getHeaderActions(): array
    {
        $action = Action::make('record')
            ->label(__('payments.collect.title'))
            ->tooltip(__('payments.collect.title'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->mountUsing(fn () => $this->form->validate())
            ->action(function (): void {
                $data = PaymentData::fromForm($this->form->getState());
                $payment = DomainActionRunner::run(fn (User $actor): Payment => app(RecordPayment::class)($actor, $data));

                Notification::make()
                    ->title(__('payments.collect.saved', ['amount' => Display::money($payment->amount_poisha)]))
                    ->success()
                    ->send();

                $this->resetForm();
            });

        return [
            self::tier2(
                $action,
                heading: fn (): string => __('payments.collect.save_heading', [
                    'amount' => Display::money($this->amount()),
                    'member' => $this->member()?->displayName() ?? '',
                ]),
                rows: fn (): array => ChangeSummary::rows(
                    [
                        'member' => __('payments.field.member'),
                        'method' => __('payments.field.method'),
                        'amount' => __('payments.field.amount'),
                        'trx' => __('payments.field.trx_id'),
                        'date' => __('payments.field.received_on'),
                    ],
                    [],
                    [
                        'member' => $this->member()?->displayName(),
                        'method' => $this->method($this->data['method'] ?? null),
                        'amount' => $this->amount(),
                        'trx' => $this->data['trx_id'] ?? null,
                        'date' => is_string($this->data['received_on'] ?? null) ? CarbonImmutable::parse($this->data['received_on']) : null,
                    ],
                ),
                showOld: false,
            ),
        ];
    }

    private function resetForm(?int $memberId = null): void
    {
        $this->form->fill([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $memberId,
            'method' => PaymentMethod::Cash->value,
            'received_on' => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString(),
        ]);
    }

    private function member(): ?Member
    {
        $id = $this->data['member_id'] ?? null;

        return is_numeric($id) ? Member::query()->find((int) $id) : null;
    }

    private function amount(): ?Money
    {
        $value = $this->data['amount'] ?? null;
        $money = is_string($value) ? Money::tryOfTaka($value) : null;

        return $money !== null && $money->isPositive() ? $money : null;
    }

    private function method(mixed $value): ?PaymentMethod
    {
        return $value instanceof PaymentMethod ? $value : PaymentMethod::tryFrom(is_string($value) ? $value : '');
    }
}
