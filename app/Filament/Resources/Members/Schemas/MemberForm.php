<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Schemas;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use App\Domain\Members\Models\NomineeRelation;
use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        $creating = fn (?Member $record): bool => $record === null;

        return $schema
            ->components([
                Section::make(__('members.member.personal_section'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name_bn')->label(__('members.member.name_bn'))->required()->maxLength(255),
                        TextInput::make('name_en')->label(__('members.member.name_en'))->required()->maxLength(255),
                        TextInput::make('guardian_name')->label(__('members.member.guardian_name'))->maxLength(255),
                        TextInput::make('nid')
                            ->label(__('members.member.nid'))
                            ->helperText(__('members.member.nid_help'))
                            ->regex('/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u'),
                        DatePicker::make('date_of_birth')->label(__('members.member.date_of_birth'))->native(false)->maxDate(now()),
                        DatePicker::make('joined_on')
                            ->label(__('members.member.joined_on'))
                            ->native(false)
                            ->required()
                            ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString()),
                        FileUpload::make('photo_path')
                            ->label(__('members.member.photo'))
                            ->image()
                            ->avatar()
                            ->disk('local')
                            ->directory('member-photos')
                            ->visibility('private')
                            ->maxSize(1024),
                    ]),
                Section::make(__('members.member.contact_section'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('mobile')
                            ->label(__('members.member.mobile'))
                            ->helperText(__('members.member.mobile_help'))
                            ->tel()
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (MobileNumber::normalize(is_string($value) ? $value : null) === null) {
                                    $fail(__('members.errors.mobile_format'));
                                }
                            }),
                        TextInput::make('email')->label(__('members.member.email'))->email(),
                        Textarea::make('address')->label(__('members.member.address'))->rows(2),
                    ]),
                Section::make(__('members.member.shares_section'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible($creating)
                    ->schema([
                        TextInput::make('shares')
                            ->label(__('members.member.shares'))
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required($creating),
                        TextInput::make('effective_from')
                            ->label(__('members.member.effective_from'))
                            ->helperText(__('members.member.effective_from_help'))
                            ->type('month')
                            ->regex('/^\d{4}-\d{2}$/')
                            ->default(fn (): string => (string) YearMonth::current())
                            ->required($creating),
                    ]),
                Section::make(__('members.member.nominees_section'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('nominees')
                            ->hiddenLabel()
                            ->addActionLabel(__('members.nominee.add'))
                            ->defaultItems(1)
                            ->minItems(1)
                            ->required()
                            ->columns(6)
                            ->schema([
                                TextInput::make('name')->label(__('members.nominee.name'))->required()->columnSpan(2),
                                Select::make('relation_id')
                                    ->label(__('members.nominee.relation'))
                                    ->options(fn (): array => NomineeRelation::options())
                                    ->required()
                                    ->native(false),
                                TextInput::make('nid')
                                    ->label(__('members.nominee.nid'))
                                    ->required()
                                    ->regex('/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u'),
                                TextInput::make('mobile')
                                    ->label(__('members.nominee.mobile'))
                                    ->tel()
                                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        if (is_string($value) && trim($value) !== '' && MobileNumber::normalize($value) === null) {
                                            $fail(__('members.errors.mobile_format'));
                                        }
                                    }),
                                TextInput::make('share_percent')
                                    ->label(__('members.nominee.share'))
                                    ->suffix('%')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        try {
                                            Bps::ofPercent((string) $value);
                                        } catch (\InvalidArgumentException) {
                                            $fail(__('money.validation.invalid'));
                                        }
                                    }),
                            ]),
                        TextEntry::make('nominee_total')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => __('members.nominee.total', ['total' => self::nomineeTotal($get('nominees'))->format(app()->getLocale())]))
                            ->color(fn (Get $get): string => in_array(self::nomineeTotal($get('nominees'))->value, [0, 10_000], true) ? 'success' : 'danger')
                            ->visible(fn (Get $get): bool => is_array($get('nominees')) && $get('nominees') !== []),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFrom(Member $member): array
    {
        return [
            'name_bn' => $member->name_bn,
            'name_en' => $member->name_en,
            'guardian_name' => $member->guardian_name,
            'nid' => $member->nid,
            'date_of_birth' => $member->date_of_birth?->toDateString(),
            'joined_on' => $member->joined_on->toDateString(),
            'photo_path' => $member->photo_path,
            'mobile' => $member->mobile,
            'email' => $member->email,
            'address' => $member->address,
            'nominees' => $member->nominees->map(fn (Nominee $nominee): array => [
                'name' => $nominee->name,
                'relation_id' => $nominee->relation_id,
                'nid' => $nominee->nid,
                'mobile' => $nominee->mobile,
                'share_percent' => Bps::of($nominee->share_bps)->toPercentString(),
            ])->values()->all(),
        ];
    }

    private static function nomineeTotal(mixed $rows): Bps
    {
        $total = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            try {
                $total += Bps::ofPercent((string) (is_array($row) ? ($row['share_percent'] ?? '') : ''))->value;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return Bps::of($total);
    }
}
