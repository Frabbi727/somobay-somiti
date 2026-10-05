<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Settings\Actions\UpdateSomitiProfile;
use App\Domain\Settings\Data\SomitiProfileData;
use App\Domain\Settings\Models\SomitiProfile;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The society's name, registration, address, contact and logo — printed on every receipt and report.
 *
 * @property-read Schema $form
 */
final class SomitiProfilePage extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.clusters.settings.somiti-profile';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'society-profile';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('somiti.title');
    }

    public function getTitle(): string
    {
        return __('somiti.title');
    }

    public function getSubheading(): string
    {
        return __('somiti.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', SomitiProfile::class);
    }

    public function mount(): void
    {
        $profile = SomitiProfile::query()->find(SomitiProfile::ID);

        $this->form->fill($profile === null ? [] : [
            ...$profile->only(['name_bn', 'name_en', 'registration_no', 'address_bn', 'address_en', 'phone', 'email', 'logo_path']),
            'registered_on' => $profile->registered_on?->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('somiti.section.identity'))
                ->icon(Heroicon::OutlinedIdentification)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('name_bn')->label(__('somiti.field.name_bn'))->required()->maxLength(200),
                    TextInput::make('name_en')->label(__('somiti.field.name_en'))->required()->maxLength(200),
                    TextInput::make('registration_no')->label(__('somiti.field.registration_no'))->maxLength(100),
                    DatePicker::make('registered_on')->label(__('somiti.field.registered_on'))->native(false)->maxDate(now()),
                ]),
            Section::make(__('somiti.section.contact'))
                ->icon(Heroicon::OutlinedMapPin)
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Textarea::make('address_bn')->label(__('somiti.field.address_bn'))->rows(2)->maxLength(500),
                    Textarea::make('address_en')->label(__('somiti.field.address_en'))->rows(2)->maxLength(500),
                    TextInput::make('phone')->label(__('somiti.field.phone'))->tel()->maxLength(20),
                    TextInput::make('email')->label(__('somiti.field.email'))->email()->maxLength(200),
                ]),
            Section::make(__('somiti.section.logo'))
                ->icon(Heroicon::OutlinedPhoto)
                ->description(__('somiti.logo_help'))
                ->schema([
                    FileUpload::make('logo_path')
                        ->hiddenLabel()
                        ->image()
                        ->imageEditor()
                        ->disk(SomitiProfile::LOGO_DISK)
                        ->directory('somiti')
                        ->visibility('private')
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(SomitiProfile::MAX_LOGO_KB),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->saveAction()];
    }

    public function saveAction(): Action
    {
        $labels = [
            'name_bn' => __('somiti.field.name_bn'), 'name_en' => __('somiti.field.name_en'),
            'registration_no' => __('somiti.field.registration_no'), 'registered_on' => __('somiti.field.registered_on'),
            'address_bn' => __('somiti.field.address_bn'), 'address_en' => __('somiti.field.address_en'),
            'phone' => __('somiti.field.phone'), 'email' => __('somiti.field.email'),
        ];

        return self::tier2(
            Action::make('save')
                ->label(__('somiti.save'))
                ->tooltip(__('somiti.save'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->mountUsing(fn () => $this->form->validate())
                ->action(function (): void {
                    DomainActionRunner::run(fn (User $actor): SomitiProfile => app(UpdateSomitiProfile::class)($actor, SomitiProfileData::fromForm($this->form->getState())));
                    Notification::make()->title(__('somiti.saved'))->success()->send();
                    $this->mount();
                }),
            heading: __('somiti.confirm'),
            rows: function () use ($labels): array {
                $profile = SomitiProfile::query()->find(SomitiProfile::ID);
                $old = $profile === null ? [] : [...$profile->only(array_keys($labels)), 'registered_on' => $profile->registered_on?->toDateString()];

                return ChangeSummary::rows($labels, $old, array_intersect_key($this->data, $labels));
            },
        );
    }
}
