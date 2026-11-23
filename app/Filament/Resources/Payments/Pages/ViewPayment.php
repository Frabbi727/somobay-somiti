<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Domain\Contributions\Models\Payment;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Display;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Payment $record
 */
final class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['member', 'allocations.due', 'advanceEntries', 'journalEntry', 'recorder', 'approver']);
    }

    public function getTitle(): string
    {
        return __('payments.singular').' · '.Display::money($this->record->amount_poisha);
    }

    protected function getHeaderActions(): array
    {
        // After a status change, reload the page so the status and the buttons match the payment.
        $reload = fn (Payment $record) => $this->redirect(self::getResource()::getUrl('view', ['record' => $record]));

        return [
            PaymentActions::approve()->after($reload),
            PaymentActions::reject()->after($reload),
            PaymentActions::cancel()->after($reload),
            PaymentActions::reverse()->after($reload),
            PaymentActions::receipt(),
        ];
    }
}
