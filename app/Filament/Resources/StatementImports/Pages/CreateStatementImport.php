<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\Pages;

use App\Domain\Accounting\Actions\ImportStatement;
use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\StatementImports\StatementImportResource;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateStatementImport extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = StatementImportResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('statements.actions.import');
    }

    protected function getCreateFormAction(): Action
    {
        return self::tier1(
            parent::getCreateFormAction()->submit(null)->label(__('statements.actions.import')),
            __('statements.actions.import_heading'),
            __('statements.actions.import_description'),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->create());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $method = $data['method'] ?? null;

        return DomainActionRunner::run(fn (User $actor): StatementImport => app(ImportStatement::class)(
            $actor,
            $method instanceof PaymentMethod ? $method : PaymentMethod::from((string) $method),
            (string) ($data['file_path'] ?? ''),
            (string) ($data['filename'] ?? 'statement.csv'),
            (array) ($data['columns'] ?? []),
        ));
    }

    protected function getCreatedNotificationTitle(): string
    {
        $import = $this->record;

        return $import instanceof StatementImport
            ? __('statements.notifications.imported', [
                'lines' => Display::digits($import->lines_count),
                'matched' => Display::digits($import->countWithStatus(StatementLineStatus::Matched)),
            ])
            : '';
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
