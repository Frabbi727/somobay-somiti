<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Integrity\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 2: control accounts equal their sub-ledgers — in total, and for 2111 member by member.
 */
final class ControlAccounts implements IntegrityCheck
{
    public function __construct(private readonly Reconciliation $reconciliation) {}

    public function key(): string
    {
        return 'control_accounts';
    }

    public function run(): array
    {
        $findings = [];

        foreach ($this->reconciliation->controlVsSubledger(CarbonImmutable::parse('9999-12-31')) as $check) {
            if (! $check->isReconciled()) {
                $findings[] = new Finding($this->key(), sprintf('%s differs from %s by %s', $check->account->code, $check->source, $check->difference()->format('en')), ['account' => $check->account->code]);
            }
        }

        $mismatches = DB::select(<<<'SQL'
            WITH gl AS (
                SELECT l.member_id, SUM(l.credit_poisha) - SUM(l.debit_poisha) AS balance
                FROM journal_lines l JOIN accounts a ON a.id = l.account_id
                WHERE a.code = ? AND l.member_id IS NOT NULL
                GROUP BY l.member_id
            ), ledger AS (
                SELECT member_id, SUM(delta_poisha) AS balance FROM advance_ledger_entries GROUP BY member_id
            )
            SELECT COALESCE(gl.member_id, ledger.member_id) AS member_id, COALESCE(gl.balance, 0) AS gl, COALESCE(ledger.balance, 0) AS ledger
            FROM gl FULL OUTER JOIN ledger ON ledger.member_id = gl.member_id
            WHERE COALESCE(gl.balance, 0) <> COALESCE(ledger.balance, 0)
            SQL, [AccountCode::MEMBER_ADVANCE]);

        foreach ($mismatches as $row) {
            $findings[] = new Finding($this->key(), "Member {$row->member_id}: 2111 shows {$row->gl} but the advance ledger {$row->ledger}", ['member_id' => (int) $row->member_id]);
        }

        return $findings;
    }
}
