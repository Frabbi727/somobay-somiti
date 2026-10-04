<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * §6.7 invariant 5: Σ dividend lines = the pool, and Σ appropriation + pool + loss offset = net
 * profit, for every posted year-end.
 */
final class YearEndTotals implements IntegrityCheck
{
    public function key(): string
    {
        return 'year_end_totals';
    }

    public function run(): array
    {
        $findings = [];

        $rows = DB::select(<<<'SQL'
            SELECT y.id, f.code, y.net_profit_poisha, y.loss_offset_poisha, y.dividend_pool_poisha, y.appropriation,
                   COALESCE((SELECT SUM(d.amount_poisha) FROM dividend_lines d WHERE d.year_end_id = y.id), 0) AS dividends
            FROM year_ends y JOIN fiscal_years f ON f.id = y.fiscal_year_id
            WHERE y.status = 'posted'
            SQL);

        foreach ($rows as $row) {
            if ((int) $row->dividends !== (int) $row->dividend_pool_poisha) {
                $findings[] = new Finding($this->key(), "Year-end {$row->code}: dividend lines total {$row->dividends}, pool is {$row->dividend_pool_poisha}", ['year_end_id' => (int) $row->id]);
            }

            if ((int) $row->net_profit_poisha > 0) {
                /** @var array<string, int> $lines */
                $lines = (array) json_decode((string) $row->appropriation, true);
                $total = array_sum($lines) + (int) $row->dividend_pool_poisha + (int) $row->loss_offset_poisha;

                if ($total !== (int) $row->net_profit_poisha) {
                    $findings[] = new Finding($this->key(), "Year-end {$row->code}: appropriation adds up to {$total}, net profit is {$row->net_profit_poisha}", ['year_end_id' => (int) $row->id]);
                }
            }
        }

        return $findings;
    }
}
