<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Pages\Collections\CollectPayment;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('collect')
                ->label(__('payments.collect.title'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => CollectPayment::getUrl())
                ->visible(fn (): bool => CollectPayment::canAccess())
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
