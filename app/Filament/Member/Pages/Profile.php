<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Portal\ChangeOwnPassword;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The member's details and nominees, and changing their own password.
 *
 * @property-read Schema $form
 */
final class Profile extends Page
{
    use ConfirmsWithTier;
    use ScopedToMember;

    protected string $view = 'filament.member.profile';

    protected static ?int $navigationSort = 70;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.profile');
    }

    public function getTitle(): string
    {
        return __('portal.nav.profile');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(3)->components([
            TextInput::make('current')->label(__('portal.profile.current'))->password()->revealable()->autocomplete('current-password')->required(),
            TextInput::make('password')->label(__('portal.login.password'))->password()->revealable()->autocomplete('new-password')->required()->minLength(ChangeOwnPassword::MIN_LENGTH)->confirmed(),
            TextInput::make('password_confirmation')->label(__('portal.profile.confirm'))->password()->revealable()->autocomplete('new-password')->required(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['member' => self::member()->load('nominees')];
    }

    public function changePasswordAction(): Action
    {
        return self::tier1(
            Action::make('changePassword')
                ->label(__('portal.profile.save'))
                ->tooltip(__('portal.profile.save'))
                ->icon(Heroicon::OutlinedKey)
                ->mountUsing(fn () => $this->form->validate())
                ->action(function (): void {
                    $data = $this->form->getState();
                    DomainActionRunner::run(fn (User $actor) => app(ChangeOwnPassword::class)($actor, (string) $data['current'], (string) $data['password']));

                    Notification::make()->title(__('portal.profile.password_saved'))->success()->send();
                    $this->form->fill();
                }),
            heading: __('portal.profile.save'),
        );
    }
}
