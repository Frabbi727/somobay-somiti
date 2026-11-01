<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Schemas;

use App\Domain\Geo\Data\PostalAddress;
use App\Domain\Members\Enums\RegistrationDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Forms\PersonFields;
use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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
        $isCreate = fn (string $operation): bool => $operation === 'create';

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
                        DatePicker::make('date_of_birth')->label(__('members.member.date_of_birth'))->native(false)->maxDate(now())->defaultFocusedDate(now()->subYears(30)->startOfYear()),
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
                        Textarea::make('address')
                            ->label(__('members.member.old_address'))
                            ->rows(2)
                            ->visible(fn (string $operation, Get $get): bool => $operation !== 'create' && filled($get('address')))
                            ->dehydratedWhenHidden(),
                        ...PersonFields::make($isCreate),
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
                            ->default(fn (): string => (string) app(ShareChanger::class)->firstOpenMonth())
                            ->required($creating),
                    ]),
                Section::make(__('members.member.documents_section'))
                    ->columnSpanFull()
                    ->schema([
                        CheckboxList::make('documents_received')
                            ->label(__('members.member.documents_received'))
                            ->options(RegistrationDocument::class)
                            ->columns(2),
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
                                Hidden::make('legacy_relation'),
                                Select::make('relation_id')
                                    ->label(__('members.nominee.relation'))
                                    ->options(fn (): array => NomineeRelation::options())
                                    ->helperText(fn (Get $get): ?string => is_string($get('legacy_relation')) && $get('legacy_relation') !== ''
                                        ? (string) __('members.nominee.legacy_relation', ['relation' => $get('legacy_relation')])
                                        : null)
                                    ->required()
                                    ->native(false),
                                TextInput::make('nid')
                                    ->label(__('members.nominee.nid'))
                                    ->required()
                                    ->regex('/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u'),
                                ...PersonFields::make($isCreate),
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
                Section::make(__('members.member.terms_section'))
                    ->columnSpanFull()
                    ->visible($creating)
                    ->schema([
                        Hidden::make('terms_version')->default(fn (): int => (int) MembershipTerms::current()->version),
                        Checkbox::make('terms_accepted')
                            ->label(fn (Get $get): string => __('terms.office_confirm', ['version' => $get('terms_version')]))
                            ->accepted()
                            ->required()
                            ->dehydrated(false),
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
            'documents_received' => array_map(fn (RegistrationDocument $document): string => $document->value, array_values($member->documents_received->all())),
            ...self::personFrom($member),
            'nominees' => $member->nominees->map(fn (Nominee $nominee): array => [
                'name' => $nominee->name,
                'relation_id' => $nominee->relation_id ?? self::matchLegacyRelation($nominee->relation),
                'legacy_relation' => $nominee->relation_id === null ? $nominee->relation : null,
                'nid' => $nominee->nid,
                'mobile' => $nominee->mobile,
                'share_percent' => Bps::of($nominee->share_bps)->toPercentString(),
                ...self::personFrom($nominee),
            ])->values()->all(),
        ];
    }

    /**
     * Gender, passport and both addresses of a member or nominee in form shape.
     *
     * @return array<string, mixed>
     */
    private static function personFrom(Member|Nominee $person): array
    {
        $present = PostalAddress::fromAttributes($person, 'present');
        $permanent = PostalAddress::fromAttributes($person, 'permanent');

        return [
            'gender' => $person->gender?->value,
            'passport_no' => $person->passport_no,
            'present_upazila_id' => $present?->upazilaId,
            'present_union_id' => $present?->unionId,
            'present_address_line' => $present?->line,
            'permanent_same_as_present' => $present !== null && $present == $permanent,
            'permanent_upazila_id' => $permanent?->upazilaId,
            'permanent_union_id' => $permanent?->unionId,
            'permanent_address_line' => $permanent?->line,
        ];
    }

    /**
     * A legacy nominee (free-text relation, no relation_id) gets the active relation whose English or
     * Bangla label matches the text, ignoring case; otherwise the office must choose one before saving.
     */
    private static function matchLegacyRelation(string $text): ?int
    {
        $needle = mb_strtolower(trim($text));

        if ($needle === '') {
            return null;
        }

        return NomineeRelation::query()->where('active', true)->orderBy('sort')->orderBy('id')->get()
            ->first(fn (NomineeRelation $relation): bool => in_array($needle, [mb_strtolower($relation->label_en), mb_strtolower($relation->label_bn)], true))
            ?->id;
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
