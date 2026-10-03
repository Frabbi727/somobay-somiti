<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Actions;

use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\CancelRatePlan;
use App\Domain\Settings\Actions\DeleteRatePlan;
use App\Domain\Settings\Actions\DuplicateRatePlan;
use App\Domain\Settings\Actions\RejectRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateImpactPreviewer;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Clusters\Settings\Resources\RatePlans\Support\RatePlanPresenter;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Reports\RateImpactDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rate plan lifecycle: submit (T2), approve (T3), send back, cancel (T3), duplicate, delete (T3).
 */
final class RatePlanActions
{
    use ConfirmsWithTier;

    public static function submit(): Action
    {
        $action = Action::make('submit')
            ->label(__('rates.actions.submit'))
            ->tooltip(__('rates.actions.submit'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->authorize('submit')
            ->action(function (RatePlan $record): void {
                DomainActionRunner::run(fn (User $actor): RatePlan => app(SubmitRatePlan::class)($actor, $record));
                self::notify(__('rates.notifications.submitted', ['code' => $record->code]));
            });

        return self::tier2(
            $action,
            heading: fn (RatePlan $record): string => __('rates.actions.submit_heading', ['code' => $record->code]),
            rows: fn (RatePlan $record): array => ChangeSummary::rows(
                RatePlanPresenter::summaryLabels(),
                [],
                RatePlanPresenter::summaryValues(RatePlanData::fromPlan($record)),
            ),
            description: __('rates.actions.submit_description'),
            showOld: false,
        )
            ->modalContent(fn (RatePlan $record): View => view('filament.rates.submit-confirmation', [
                'rows' => ChangeSummary::rows(RatePlanPresenter::summaryLabels(), [], RatePlanPresenter::summaryValues(RatePlanData::fromPlan($record))),
                'preview' => app(RateImpactPreviewer::class)->preview($record),
            ]))
            ->extraModalFooterActions([self::downloadImpact()])
            ->modalWidth(Width::FourExtraLarge);
    }

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('rates.actions.approve'))
            ->tooltip(__('rates.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (RatePlan $record, array $data): void {
                $plan = DomainActionRunner::run(fn (User $actor): RatePlan => app(ApproveRatePlan::class)($actor, $record, self::text($data, 'comment')));

                self::notify($plan->status === RatePlanStatus::Approved
                    ? __('rates.notifications.approved', ['code' => $plan->code, 'month' => Display::yearMonth($plan->effective_from)])
                    : __('rates.notifications.approval_recorded', ['code' => $plan->code]));
            });

        return self::tier3(
            $action,
            heading: fn (RatePlan $record): string => __('rates.actions.approve_heading', ['code' => $record->code]),
            expected: fn (RatePlan $record): string => $record->code,
            submitLabel: fn (RatePlan $record): string => __('rates.actions.approve_submit', ['code' => $record->code]),
            description: fn (RatePlan $record): string => __('rates.actions.approve_description', ['month' => Display::yearMonth($record->effective_from)]),
            fields: [
                Textarea::make('comment')->label(__('rates.actions.comment'))->rows(2)->maxLength(1000),
            ],
        )
            ->modalContent(fn (RatePlan $record): View => view('filament.rates.impact', [
                'preview' => app(RateImpactPreviewer::class)->preview($record),
            ]))
            ->extraModalFooterActions([self::downloadImpact()])
            ->modalWidth(Width::FourExtraLarge);
    }

    /**
     * Excel download of the full impact preview; usable from modals and page headers.
     */
    public static function downloadImpact(): Action
    {
        return Action::make('downloadImpact')
            ->label(__('rates.impact.download'))
            ->tooltip(__('rates.impact.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (RatePlan $record): bool => in_array($record->status, [RatePlanStatus::Draft, RatePlanStatus::PendingApproval], true))
            ->action(function (RatePlan $record): StreamedResponse {
                $document = app(RateImpactDocument::class);
                $content = $document->excel($record);

                return response()->streamDownload(function () use ($content): void {
                    echo $content;
                }, $document->filename($record), [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]);
            });
    }

    public static function reject(): Action
    {
        $action = Action::make('reject')
            ->label(__('rates.actions.reject'))
            ->tooltip(__('rates.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('approve')
            ->action(function (RatePlan $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): RatePlan => app(RejectRatePlan::class)($actor, $record, self::text($data, 'comment') ?? ''));
                self::notify(__('rates.notifications.rejected', ['code' => $record->code]));
            });

        return self::tier3(
            $action,
            heading: fn (RatePlan $record): string => __('rates.actions.reject_heading', ['code' => $record->code]),
            expected: fn (RatePlan $record): string => $record->code,
            submitLabel: __('rates.actions.reject'),
            fields: [Textarea::make('comment')->label(__('rates.actions.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('rates.actions.cancel'))
            ->tooltip(__('rates.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->action(function (RatePlan $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): RatePlan => app(CancelRatePlan::class)($actor, $record, self::text($data, 'reason') ?? ''));
                self::notify(__('rates.notifications.cancelled', ['code' => $record->code]));
            });

        return self::tier3(
            $action,
            heading: fn (RatePlan $record): string => __('rates.actions.cancel_heading', ['code' => $record->code]),
            expected: fn (RatePlan $record): string => $record->code,
            submitLabel: fn (RatePlan $record): string => __('rates.actions.cancel_submit', ['code' => $record->code]),
            fields: [
                Textarea::make('reason')->label(__('rates.actions.reason'))->required()->minLength(5)->rows(2),
            ],
        );
    }

    public static function duplicate(): Action
    {
        $action = Action::make('duplicate')
            ->label(__('rates.actions.duplicate'))
            ->tooltip(__('rates.actions.duplicate'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->authorize('duplicate')
            ->action(function (RatePlan $record, Action $action): void {
                $copy = DomainActionRunner::run(fn (User $actor): RatePlan => app(DuplicateRatePlan::class)($actor, $record));
                self::notify(__('rates.notifications.duplicated', ['code' => $copy->code]));
                $action->redirect(RatePlanResource::getUrl('edit', ['record' => $copy]));
            });

        return self::tier1(
            $action,
            heading: fn (RatePlan $record): string => __('rates.actions.duplicate_heading', ['code' => $record->code]),
        );
    }

    public static function delete(): Action
    {
        $action = Action::make('delete')
            ->label(__('filament-actions::delete.single.label'))
            ->tooltip(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->action(function (RatePlan $record, Action $action): void {
                DomainActionRunner::run(fn (User $actor) => app(DeleteRatePlan::class)($actor, $record));
                self::notify(__('rates.notifications.deleted', ['code' => $record->code]));
                $action->redirect(RatePlanResource::getUrl('index'));
            });

        return self::tier3(
            $action,
            heading: fn (RatePlan $record): string => __('rates.actions.delete_heading', ['code' => $record->code]),
            expected: fn (RatePlan $record): string => $record->code,
            submitLabel: fn (RatePlan $record): string => __('rates.actions.delete_submit', ['code' => $record->code]),
        );
    }

    private static function notify(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
