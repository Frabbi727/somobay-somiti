<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Members\Registration\Actions\UpdateRegistrationApprovalChain;
use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Role;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
final class RegistrationApprovalsPage extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.clusters.settings.somiti-profile';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'registration-approvals';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('registration.chain.title');
    }

    public function getTitle(): string
    {
        return __('registration.chain.title');
    }

    public function getSubheading(): string
    {
        return __('registration.chain.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', SomitiProfile::class);
    }

    public function mount(): void
    {
        $this->form->fill(['steps' => array_map(fn (Role $role): array => ['role' => $role->value], SomitiProfile::current()->registrationApprovalChain())]);
    }

    public function form(Schema $schema): Schema
    {
        $options = [];

        foreach (UpdateRegistrationApprovalChain::ALLOWED as $role) {
            $options[$role->value] = $role->getLabel();
        }

        return $schema->statePath('data')->components([
            Repeater::make('steps')
                ->hiddenLabel()
                ->simple(Select::make('role')->label(__('registration.chain.role'))->options($options)->required()->native(false))
                ->reorderable()
                ->minItems(1)
                ->addActionLabel(__('registration.chain.add')),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->saveAction()];
    }

    public function saveAction(): Action
    {
        return self::tier2(
            Action::make('save')
                ->label(__('registration.actions.save_chain'))
                ->tooltip(__('registration.actions.save_chain'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->mountUsing(fn () => $this->form->validate())
                ->action(function (): void {
                    DomainActionRunner::run(fn (User $actor): SomitiProfile => app(UpdateRegistrationApprovalChain::class)($actor, $this->roles()));
                    Notification::make()->title(__('registration.notifications.chain_saved'))->success()->send();
                    $this->mount();
                }),
            heading: __('registration.chain.title'),
            rows: fn (): array => ChangeSummary::rows(
                ['chain' => __('registration.chain.title')],
                ['chain' => $this->describe(SomitiProfile::current()->registrationApprovalChain())],
                ['chain' => $this->describe($this->roles())],
            ),
        );
    }

    /**
     * @return list<Role>
     */
    private function roles(): array
    {
        $roles = [];

        foreach ((array) ($this->data['steps'] ?? []) as $row) {
            $value = is_array($row) ? ($row['role'] ?? null) : $row;

            if (is_string($value) && Role::tryFrom($value) !== null) {
                $roles[] = Role::from($value);
            }
        }

        return $roles;
    }

    /**
     * @param  list<Role>  $roles
     */
    private function describe(array $roles): string
    {
        return implode(' → ', array_map(fn (Role $role): string => $role->getLabel(), $roles));
    }
}
