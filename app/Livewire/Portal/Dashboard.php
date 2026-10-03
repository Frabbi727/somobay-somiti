<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Accounting\AccountCode;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

final class Dashboard extends PortalComponent
{
    public function render(): View
    {
        $member = $this->member();
        $paidThrough = app(PaidThroughCalculator::class);

        $savings = (int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', AccountCode::MEMBER_SAVINGS)
            ->where('l.member_id', $member->id)
            ->sum(DB::raw('l.credit_poisha - l.debit_poisha'));

        $open = Due::query()->where('member_id', $member->id)->where('status', DueStatus::Open)->where('outstanding_poisha', '>', 0)->get();

        return view('livewire.portal.dashboard', [
            'member' => $member,
            'savings' => Money::ofPoisha($savings),
            'advance' => app(AdvanceLedger::class)->balance($member->id),
            'paidThrough' => $paidThrough->for($member),
            'estimate' => $paidThrough->estimatedMonths($member),
            'outstanding' => Money::sum($open->map(fn (Due $due): Money => $due->outstanding_poisha)),
            'shares' => $member->sharesIn(YearMonth::current()),
            'payments' => Payment::query()->where('member_id', $member->id)->with('journalEntry')->latest('id')->limit(5)->get(),
        ])->title(__('portal.nav.dashboard'));
    }
}
