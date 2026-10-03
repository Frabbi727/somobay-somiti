<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Contracts\AdvanceBalances;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The member advance sub-ledger (2111). Callers must hold the member row lock while appending,
 * so balance_after is computed from a stable previous balance.
 */
final class AdvanceLedger implements AdvanceBalances
{
    public function balance(int $memberId): Money
    {
        $latest = AdvanceLedgerEntry::query()->where('member_id', $memberId)->orderByDesc('id')->first();

        return $latest === null ? Money::zero() : $latest->balance_after_poisha;
    }

    /**
     * @param  array{payment_id?: int|null, due_id?: int|null, journal_entry_id?: int|null, reverses_entry_id?: int|null, created_by?: int|null}  $links
     */
    public function append(int $memberId, AdvanceEntryKind $kind, Money $delta, array $links = []): AdvanceLedgerEntry
    {
        $after = $this->balance($memberId)->plus($delta);

        if ($after->isNegative()) {
            throw DomainRuleViolation::because('payments.errors.advance_negative');
        }

        return AdvanceLedgerEntry::query()->create([
            'member_id' => $memberId,
            'kind' => $kind,
            'delta_poisha' => $delta,
            'balance_after_poisha' => $after,
            ...$links,
        ]);
    }

    public function all(): array
    {
        $rows = DB::table('advance_ledger_entries as e')
            ->whereIn('e.id', fn ($query) => $query->from('advance_ledger_entries')->selectRaw('MAX(id)')->groupBy('member_id'))
            ->where('e.balance_after_poisha', '>', 0)
            ->pluck('e.balance_after_poisha', 'e.member_id');

        $balances = [];

        foreach ($rows as $memberId => $balance) {
            $balances[(int) $memberId] = Money::ofPoisha((int) $balance);
        }

        return $balances;
    }
}
