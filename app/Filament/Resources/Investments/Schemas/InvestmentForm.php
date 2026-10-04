<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Schemas;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Investments\Enums\InvestmentType;
use App\Filament\Forms\Components\MoneyInput;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class InvestmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('type')->label(__('investments.field.type'))->options(InvestmentType::class)->default(InvestmentType::FixedDeposit->value)->required()->native(false),
                    TextInput::make('institution')->label(__('investments.field.institution'))->required()->minLength(2)->maxLength(255),
                    TextInput::make('instrument_no')->label(__('investments.field.instrument_no'))->maxLength(60),
                    MoneyInput::make('principal')->label(__('investments.field.principal'))->required(),
                    Select::make('funded_from')->label(__('investments.field.funded_from'))->options(PaymentMethod::class)->default(PaymentMethod::Bank->value)->required()->native(false),
                    TextInput::make('expected_rate')->label(__('investments.field.expected_rate'))->regex('/^\d{1,3}(\.\d{1,2})?$/')->maxLength(6),
                    DatePicker::make('invested_on')
                        ->label(__('investments.field.invested_on'))
                        ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                        ->maxDate(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                        ->native(false)
                        ->required(),
                    DatePicker::make('matures_on')->label(__('investments.field.matures_on'))->after('invested_on')->native(false),
                    Select::make('resolution_id')
                        ->label(__('investments.field.resolution'))
                        ->options(fn (): array => Resolution::query()
                            ->where('subject', ResolutionSubject::Investment)
                            ->where('status', ResolutionStatus::Passed)
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (Resolution $resolution): array => [$resolution->id => $resolution->displayName()])
                            ->all())
                        ->required(fn (): bool => ResolutionSubject::Investment->isRequired())
                        ->native(false)
                        ->columnSpanFull(),
                    Textarea::make('notes')->label(__('investments.field.notes'))->rows(2)->columnSpanFull(),
                    FileUpload::make('attachment_path')
                        ->label(__('investments.field.attachment'))
                        ->disk('local')
                        ->directory('investments')
                        ->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize((int) config('somiti.max_proof_kb'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'type' => __('investments.field.type'),
            'institution' => __('investments.field.institution'),
            'principal' => __('investments.field.principal'),
            'funded_from' => __('investments.field.funded_from'),
            'invested_on' => __('investments.field.invested_on'),
            'matures_on' => __('investments.field.matures_on'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function summaryValues(array $data): array
    {
        $type = $data['type'] ?? null;
        $from = $data['funded_from'] ?? null;
        $principal = $data['principal'] ?? null;

        return [
            'type' => $type instanceof InvestmentType ? $type : InvestmentType::tryFrom((string) $type),
            'institution' => $data['institution'] ?? null,
            'principal' => is_string($principal) && $principal !== '' ? '৳ '.$principal : null,
            'funded_from' => $from instanceof PaymentMethod ? $from : PaymentMethod::tryFrom((string) $from),
            'invested_on' => $data['invested_on'] ?? null,
            'matures_on' => $data['matures_on'] ?? null,
        ];
    }
}
