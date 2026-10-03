<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Contributions\Models\Payment;
use Illuminate\Contracts\View\View;
use Livewire\WithPagination;

final class Receipts extends PortalComponent
{
    use WithPagination;

    public function render(): View
    {
        return view('livewire.portal.receipts', [
            'payments' => Payment::query()
                ->where('member_id', $this->member()->id)
                ->with('journalEntry')
                ->latest('received_on')
                ->latest('id')
                ->paginate(20),
        ])->title(__('portal.nav.receipts'));
    }
}
