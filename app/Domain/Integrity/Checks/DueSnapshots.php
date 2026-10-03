<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 7: the rates copied onto each due match the (immutable) plan it names.
 */
final class DueSnapshots implements IntegrityCheck
{
    /**
     * @var list<string>
     */
    private const array FIELDS = ['share_unit_poisha', 'service_charge_per_share_poisha', 'registration_fee_per_share_poisha', 'due_day', 'grace_days'];

    public function key(): string
    {
        return 'due_snapshots';
    }

    public function run(): array
    {
        $conditions = implode(' OR ', array_map(
            fn (string $field): string => "(d.snapshot->>'{$field}')::bigint IS DISTINCT FROM p.{$field}::bigint",
            self::FIELDS,
        ));

        $rows = DB::select(
            "SELECT d.id, p.code FROM dues d JOIN rate_plans p ON p.id = d.rate_plan_id WHERE (d.snapshot->>'rate_plan_id')::bigint <> p.id OR {$conditions}",
        );

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Due {$row->id} no longer matches rate plan {$row->code}", ['due_id' => (int) $row->id]),
            $rows,
        ));
    }
}
