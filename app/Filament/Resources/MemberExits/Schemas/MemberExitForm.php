<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits\Schemas;

use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Filament\Forms\Components\MoneyInput;
use App\Support\Time\YearMonth;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MemberExitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('member_id')
                        ->label(__('exits.field.member'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Member::query()
                            ->where('status', '!=', MemberStatus::Exited)
                            ->where(fn ($query) => $query
                                ->where('member_no', 'ilike', "%{$search}%")
                                ->orWhere('name_en', 'ilike', "%{$search}%")
                                ->orWhere('name_bn', 'ilike', "%{$search}%"))
                            ->orderBy('member_no')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Member $member): array => [$member->id => $member->displayName()])
                            ->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => Member::query()->find((int) $value)?->displayName())
                        ->required(),
                    Select::make('reason_type')->label(__('exits.field.reason_type'))->options(ExitReason::class)->default(ExitReason::Voluntary->value)->required()->native(false),
                    TextInput::make('exit_month')
                        ->label(__('exits.field.exit_month'))
                        ->helperText(__('exits.field.exit_month_help'))
                        ->type('month')
                        ->regex('/^\d{4}-\d{2}$/')
                        ->default(fn (): string => (string) YearMonth::current()->previous())
                        ->required(),
                    MoneyInput::make('exit_fee')->label(__('exits.field.exit_fee'))->default('0'),
                    Select::make('resolution_id')
                        ->label(__('exits.field.resolution'))
                        ->options(fn (): array => Resolution::query()->where('subject', ResolutionSubject::Exit)->where('status', ResolutionStatus::Passed)->orderByDesc('id')->get()
                            ->mapWithKeys(fn (Resolution $resolution): array => [$resolution->id => $resolution->displayName()])->all())
                        ->required(fn (): bool => ResolutionSubject::Exit->isRequired())
                        ->native(false)
                        ->columnSpanFull(),
                    Textarea::make('reason')->label(__('exits.field.reason'))->required()->minLength(5)->rows(2)->columnSpanFull(),
                ]),
        ]);
    }
}
