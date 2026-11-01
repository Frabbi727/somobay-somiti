<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Actions;

use App\Domain\Members\Terms\Actions\PublishTerms;
use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\MembershipTermsResource;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Rules lifecycle: start a draft from the current version (T1), publish it (T3).
 */
final class MembershipTermsActions
{
    use ConfirmsWithTier;

    public static function newDraft(): Action
    {
        $action = Action::make('newDraft')
            ->label(__('terms.admin.new_draft'))
            ->tooltip(__('terms.admin.new_draft'))
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->button()
            ->visible(fn (): bool => Gate::allows('create', MembershipTerms::class) && MembershipTerms::draft() === null)
            ->action(function (Action $action): void {
                $current = MembershipTerms::current();

                $draft = DomainActionRunner::run(fn (User $actor): MembershipTerms => app(SaveTermsDraft::class)(
                    $actor,
                    $current->rules_bn,
                    $current->rules_en,
                    $current->declaration_bn,
                    $current->declaration_en,
                ));

                Notification::make()->title(__('terms.admin.draft_created'))->success()->send();
                $action->redirect(MembershipTermsResource::getUrl('edit', ['record' => $draft]));
            });

        return self::tier1(
            $action,
            heading: __('terms.admin.new_draft_heading'),
            description: __('terms.admin.new_draft_description'),
        );
    }

    public static function publish(): Action
    {
        $action = Action::make('publish')
            ->label(__('terms.admin.publish'))
            ->tooltip(__('terms.admin.publish'))
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->authorize('publish')
            ->action(function (MembershipTerms $record, Action $action): void {
                $published = DomainActionRunner::run(fn (User $actor): MembershipTerms => app(PublishTerms::class)($actor, $record));

                Notification::make()
                    ->title(__('terms.admin.published', ['version' => Display::digits((int) $published->version)]))
                    ->success()
                    ->send();
                $action->redirect(MembershipTermsResource::getUrl('view', ['record' => $published]));
            });

        return self::tier3(
            $action,
            heading: fn (): string => __('terms.admin.publish_heading', ['version' => Display::digits(self::nextVersion())]),
            expected: fn (): string => (string) self::nextVersion(),
            submitLabel: fn (): string => __('terms.admin.publish_submit', ['version' => Display::digits(self::nextVersion())]),
            description: __('terms.admin.publish_warning'),
        );
    }

    private static function nextVersion(): int
    {
        return (int) MembershipTerms::query()->max('version') + 1;
    }
}
