<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Registration\Services\RegistrationTimeline;
use App\Filament\Member\Concerns\ScopedToApplicant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Where someone still registering lands: how far their registration has got, and a way into the form.
 */
final class RegistrationStatus extends Page
{
    use ScopedToApplicant;

    protected string $view = 'filament.member.registration-status';

    protected static ?string $slug = 'registration-status';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getNavigationLabel(): string
    {
        return __('registration.portal.nav');
    }

    public function getTitle(): string
    {
        return __('registration.portal.status_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(fn (): string => self::application()->nextAction()->getLabel())
                ->tooltip(__('registration.actions.edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->url(fn (): string => Registration::getUrl())
                ->visible(fn (): bool => self::application()->status->isEditable()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $application = self::application();
        $timeline = app(RegistrationTimeline::class);

        return [
            'headline' => $timeline->headline($application),
            'message' => $timeline->message($application),
            'decision' => $timeline->decision($application),
            'steps' => $timeline->steps($application),
        ];
    }
}
