<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Filament\Pages\Collections\CollectPayment;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /**
     * Only pending payments can be ticked (see PaymentsTable). Filament would load every filtered
     * payment to count them for "select all" — 2,000+ rows on a busy list — so count them in SQL.
     */
    public function getAllSelectableTableRecordsCount(): int
    {
        $query = $this->getFilteredTableQuery();

        return $query === null ? 0 : $query->where('status', PaymentStatus::Pending)->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('collect')
                ->label(__('payments.collect.title'))
                ->tooltip(__('payments.collect.title'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->url(fn (): string => CollectPayment::getUrl())
                ->visible(fn (): bool => CollectPayment::canAccess())
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
