<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationNominee;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Member\Concerns\ScopedToApplicant;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Bps;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The member's own registration form on the web: the same five steps as the app. Each step is
 * saved as a draft when the member moves on; submitting shows a summary first (T2).
 *
 * The photo is never taken from the form state as a path (the browser can change that state):
 * it is saved only at submit, and only when this form has just stored a new upload.
 *
 * @property-read Schema $form
 */
final class Registration extends Page
{
    use ConfirmsWithTier, ScopedToApplicant;

    /** @var list<string> */
    private const array PERSONAL_FIELDS = ['name_bn', 'name_en', 'guardian_name', 'nid', 'date_of_birth'];

    /** @var list<string> */
    private const array CONTACT_FIELDS = ['email', 'address'];

    private const string NID_PATTERN = '/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u';

    protected string $view = 'filament.member.registration';

    protected static ?string $slug = 'registration';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public array $data = [];

    /** One key per opening of the form: a double submit or a retry submits once. */
    #[Locked]
    public string $idempotencyKey = '';

    public function getTitle(): string
    {
        return __('registration.portal.form_title');
    }

    public function mount(): void
    {
        $application = self::application()->load('nominees');

        if (! $application->status->isEditable()) {
            $this->redirect(RegistrationStatus::getUrl());

            return;
        }

        $this->idempotencyKey = (string) Str::uuid();
        $nominees = $application->nominees->map(fn (MemberApplicationNominee $nominee): array => [
            'name' => $nominee->name,
            'relation_id' => $nominee->relation_id,
            'nid' => $nominee->nid,
            'mobile' => $nominee->mobile,
            'share_percent' => $nominee->share()->toPercentString(),
        ])->values()->all();

        $this->form->fill([
            ...$application->only(['name_bn', 'name_en', 'guardian_name', 'nid', 'email', 'address', 'photo_path', 'requested_shares']),
            'date_of_birth' => $application->date_of_birth?->toDateString(),
            'nominees' => $nominees === [] ? [['share_percent' => '100']] : $nominees,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make(__('registration.steps.personal'))
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(2)
                    ->afterValidation(fn () => $this->saveDraft(self::PERSONAL_FIELDS))
                    ->schema([
                        TextInput::make('name_bn')->label(__('members.member.name_bn'))->required()->maxLength(255),
                        TextInput::make('name_en')->label(__('members.member.name_en'))->required()->maxLength(255),
                        TextInput::make('guardian_name')->label(__('members.member.guardian_name'))->maxLength(255),
                        TextInput::make('nid')->label(__('members.member.nid'))->helperText(__('members.member.nid_help'))->regex(self::NID_PATTERN),
                        DatePicker::make('date_of_birth')->label(__('members.member.date_of_birth'))->native(false)->maxDate(now()),
                        FileUpload::make('photo_path')->label(__('registration.field.photo'))->image()->avatar()->disk('local')->directory('member-photos')->visibility('private')->maxSize(1024)
                            ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => $file === self::application()->photo_path),
                    ]),
                Step::make(__('registration.steps.contact'))
                    ->icon(Heroicon::OutlinedPhone)
                    ->columns(2)
                    ->afterValidation(fn () => $this->saveDraft(self::CONTACT_FIELDS))
                    ->schema([
                        TextEntry::make('mobile')->label(__('members.member.mobile'))->state(fn (): string => self::application()->mobile),
                        TextInput::make('email')->label(__('members.member.email'))->email()->maxLength(255),
                        Textarea::make('address')->label(__('members.member.address'))->rows(2)->maxLength(1000)->columnSpanFull(),
                    ]),
                Step::make(__('registration.steps.nominees'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->afterValidation(fn () => $this->saveDraft(['nominees']))
                    ->schema([
                        Repeater::make('nominees')
                            ->hiddenLabel()
                            ->addActionLabel(__('members.nominee.add'))
                            ->minItems(1)
                            ->columns(['default' => 1, 'md' => 5])
                            ->schema([
                                TextInput::make('name')->label(__('members.nominee.name'))->required(),
                                Select::make('relation_id')->label(__('members.nominee.relation'))->options(fn (): array => NomineeRelation::options())->required()->native(false),
                                TextInput::make('nid')->label(__('members.nominee.nid'))->required()->regex(self::NID_PATTERN),
                                TextInput::make('mobile')->label(__('members.nominee.mobile'))->tel(),
                                TextInput::make('share_percent')->label(__('members.nominee.share'))->suffix('%')->required()->live(onBlur: true),
                            ]),
                        TextEntry::make('nominee_total')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => __('registration.portal.nominee_total', ['total' => self::nomineeTotal($get('nominees'))->format(app()->getLocale())])),
                    ]),
                Step::make(__('registration.steps.shares'))
                    ->icon(Heroicon::OutlinedSquare3Stack3d)
                    ->afterValidation(fn () => $this->saveDraft(['requested_shares']))
                    ->schema([
                        TextInput::make('requested_shares')->label(__('registration.field.requested_shares'))->integer()->minValue(1)->maxValue(1000)->required(),
                    ]),
                Step::make(__('registration.steps.review'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->schema([
                        TextEntry::make('review')
                            ->hiddenLabel()
                            ->state(fn (): HtmlString => new HtmlString(ChangeSummary::view($this->summaryRows(), showOld: false)->render())),
                    ]),
            ])->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit">{{ $label }}</x-filament::button>', ['label' => __('registration.actions.submit')]))),
        ]);
    }

    public function submit(): void
    {
        $this->mountAction('submit');
    }

    public function submitAction(): Action
    {
        return self::tier2(
            Action::make('submit')
                ->label(__('registration.actions.submit'))
                ->tooltip(__('registration.actions.submit'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->action(function (): void {
                    $hasNewUpload = $this->hasNewPhotoUpload();
                    $state = $this->form->getState();

                    if (! $hasNewUpload || ! is_string($state['photo_path'] ?? null)) {
                        unset($state['photo_path']);
                    }

                    $this->save($state);
                    DomainActionRunner::run(fn (User $actor): MemberApplication => app(SubmitRegistration::class)(self::application(), $this->idempotencyKey));
                    $this->redirect(RegistrationStatus::getUrl());
                }),
            heading: __('registration.actions.submit_heading'),
            rows: fn (): array => $this->summaryRows(),
            description: __('registration.actions.submit_description'),
            showOld: false,
        );
    }

    /**
     * Saves the fields of the step the member just finished. The photo is never part of a step
     * save: it is stored at submit.
     *
     * @param  list<string>  $fields
     */
    private function saveDraft(array $fields): void
    {
        $this->save(Arr::only($this->data, array_values(array_diff($fields, ['photo_path']))));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function save(array $input): void
    {
        DomainActionRunner::run(fn (User $actor): MemberApplication => app(SaveRegistrationDraft::class)(self::application(), RegistrationDraft::fromInput($input)));
    }

    /**
     * Whether the photo field holds exactly one file the browser has just uploaded — not a path.
     */
    private function hasNewPhotoUpload(): bool
    {
        $photo = $this->data['photo_path'] ?? null;

        if ($photo instanceof TemporaryUploadedFile) {
            return true;
        }

        return is_array($photo) && count($photo) === 1 && reset($photo) instanceof TemporaryUploadedFile;
    }

    /**
     * @return list<array{label: string, old: string, new: string}>
     */
    private function summaryRows(): array
    {
        $nominees = collect((array) ($this->data['nominees'] ?? []))
            ->filter(fn (mixed $row): bool => is_array($row) && trim((string) ($row['name'] ?? '')) !== '')
            ->map(fn (array $row): string => sprintf('%s (%s%%)', $row['name'], $row['share_percent'] ?? '0'))
            ->implode(', ');

        return ChangeSummary::rows([
            'name_bn' => __('members.member.name_bn'),
            'name_en' => __('members.member.name_en'),
            'guardian_name' => __('members.member.guardian_name'),
            'nid' => __('members.member.nid'),
            'date_of_birth' => __('members.member.date_of_birth'),
            'email' => __('members.member.email'),
            'address' => __('members.member.address'),
            'requested_shares' => __('registration.field.requested_shares'),
            'nominees' => __('members.member.nominees_section'),
        ], [], [...$this->data, 'nominees' => $nominees]);
    }

    private static function nomineeTotal(mixed $rows): Bps
    {
        $total = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            try {
                $total += Bps::ofPercent((string) (is_array($row) ? ($row['share_percent'] ?? '') : ''))->value;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return Bps::of($total);
    }
}
