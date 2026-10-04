<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Settings\Actions\UpdateRolePermissions;
use App\Domain\Settings\Services\RolePermissions;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The president ticks which role holds which permission. The super admin and the auditor can look.
 */
final class RolePermissionsPage extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.clusters.settings.role-permissions';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'role-permissions';

    protected static ?int $navigationSort = 45;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    /**
     * Permission case name => role value => granted (case names keep the keys free of dots).
     *
     * @var array<string, array<string, bool>>
     */
    public array $grid = [];

    public static function getNavigationLabel(): string
    {
        return __('permissions.title');
    }

    public function getTitle(): string
    {
        return __('permissions.title');
    }

    public function getSubheading(): string
    {
        return __('permissions.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyOf(Role::President, Role::SuperAdmin, Role::Auditor);
    }

    public static function canEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('manageRolePermissions');
    }

    public function mount(): void
    {
        $this->grid = $this->fromStored(app(RolePermissions::class)->grid());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $groups = [];
        foreach (Permission::cases() as $permission) {
            $groups[$permission->group()][] = $permission;
        }

        return ['groups' => $groups, 'roles' => Permission::editableRoles(), 'canEdit' => self::canEdit()];
    }

    protected function getHeaderActions(): array
    {
        return [$this->saveAction(), $this->resetAction()];
    }

    public function resetAction(): Action
    {
        return Action::make('reload')
            ->label(__('permissions.reload'))
            ->tooltip(__('permissions.reload_help'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => self::canEdit())
            ->action(fn () => $this->mount());
    }

    public function saveAction(): Action
    {
        $action = Action::make('savePermissions')
            ->label(__('permissions.save'))
            ->tooltip(__('permissions.save'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('primary')
            ->visible(fn (): bool => self::canEdit())
            ->action(function (): void {
                $changes = DomainActionRunner::run(fn (User $actor): array => app(UpdateRolePermissions::class)($actor, $this->toStored()));

                Notification::make()->title(__('permissions.saved', ['count' => Display::digits(count($changes))]))->success()->send();
                $this->mount();
            });

        return self::tier3(
            $action,
            heading: __('permissions.confirm_heading'),
            expected: fn (): string => (string) count($this->pendingChanges()),
            submitLabel: __('permissions.save'),
            description: fn (): HtmlString => $this->changesSummary(),
        );
    }

    /**
     * Changes between the ticked boxes and what is stored, for the confirmation.
     *
     * @return list<array{permission: Permission, role: Role, granted: bool}>
     */
    private function pendingChanges(): array
    {
        $stored = app(RolePermissions::class)->grid();
        $wanted = $this->toStored();
        $changes = [];

        foreach (Permission::cases() as $permission) {
            foreach (Permission::editableRoles() as $role) {
                if ($wanted[$permission->value][$role->value] !== $stored[$permission->value][$role->value]) {
                    $changes[] = ['permission' => $permission, 'role' => $role, 'granted' => $wanted[$permission->value][$role->value]];
                }
            }
        }

        return $changes;
    }

    private function changesSummary(): HtmlString
    {
        $changes = $this->pendingChanges();

        if ($changes === []) {
            return new HtmlString(e(__('permissions.errors.nothing_changed')));
        }

        $items = array_map(fn (array $change): string => '<li>'.e(__($change['granted'] ? 'permissions.change.granted' : 'permissions.change.removed', [
            'role' => $change['role']->getLabel(),
            'permission' => $change['permission']->getLabel(),
        ])).'</li>', $changes);

        return new HtmlString('<ul class="list-disc ps-5 text-start">'.implode('', $items).'</ul>');
    }

    /**
     * @param  array<string, array<string, bool>>  $stored
     * @return array<string, array<string, bool>>
     */
    private function fromStored(array $stored): array
    {
        $grid = [];
        foreach (Permission::cases() as $permission) {
            $grid[$permission->name] = $stored[$permission->value];
        }

        return $grid;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function toStored(): array
    {
        $stored = [];
        foreach (Permission::cases() as $permission) {
            foreach (Permission::editableRoles() as $role) {
                $stored[$permission->value][$role->value] = (bool) ($this->grid[$permission->name][$role->value] ?? false);
            }
        }

        return $stored;
    }
}
