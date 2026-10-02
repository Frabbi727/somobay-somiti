<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\VoucherNumber;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\FiscalYear;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out the next voucher number, e.g. RV-2026-27-000001 (BR-18).
 *
 * The sequence row stays locked until the surrounding transaction ends, so concurrent
 * postings queue up, and a rolled-back posting also rolls back its number: no gaps.
 */
final class NextVoucherNumber
{
    public function __invoke(FiscalYear $fiscalYear, VoucherType $type): VoucherNumber
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Voucher numbers must be taken inside the posting transaction.');
        }

        $key = ['fiscal_year_id' => $fiscalYear->id, 'voucher_type' => $type->value];

        DB::table('voucher_sequences')->insertOrIgnore([
            ...$key,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('voucher_sequences')->where($key)->lockForUpdate()->first(['last_number', 'last_hash']);

        if ($row === null) {
            throw new LogicException('Voucher sequence row is missing.');
        }

        $next = (int) $row->last_number + 1;

        DB::table('voucher_sequences')->where($key)->update(['last_number' => $next, 'updated_at' => now()]);

        return new VoucherNumber(
            number: sprintf('%s-%s-%06d', $type->value, $fiscalYear->code, $next),
            sequence: $next,
            previousHash: is_string($row->last_hash) ? $row->last_hash : null,
        );
    }

    /**
     * Records the hash of the entry that just took a number, extending the chain.
     */
    public function recordHash(FiscalYear $fiscalYear, VoucherType $type, string $hash): void
    {
        DB::table('voucher_sequences')
            ->where(['fiscal_year_id' => $fiscalYear->id, 'voucher_type' => $type->value])
            ->update(['last_hash' => $hash]);
    }
}
