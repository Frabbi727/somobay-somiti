<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Models\Due;
use Illuminate\Contracts\View\View;
use Livewire\WithPagination;

final class Dues extends PortalComponent
{
    use WithPagination;

    public bool $openOnly = true;

    public function render(): View
    {
        return view('livewire.portal.dues', [
            'dues' => Due::query()
                ->where('member_id', $this->member()->id)
                ->when($this->openOnly, fn ($query) => $query->where('status', DueStatus::Open))
                ->orderByDesc('month')
                ->orderBy('id')
                ->paginate(24),
        ])->title(__('portal.nav.dues'));
    }
}
