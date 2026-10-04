<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Models\User;
use App\Policies\StatementLinePolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A statement row. Positive amounts are money into the account (a debit in the books), negative
 * amounts money out. Matched one-to-one with a journal line on that account.
 *
 * @property int $id
 * @property int $statement_import_id
 * @property PaymentMethod $method
 * @property int $line_no
 * @property CarbonImmutable $transacted_on
 * @property string|null $description
 * @property string|null $reference
 * @property Money $amount_poisha
 * @property Money|null $balance_poisha
 * @property string $fingerprint
 * @property StatementLineStatus $status
 * @property int|null $journal_line_id
 * @property int|null $matched_by
 * @property CarbonImmutable|null $matched_at
 * @property string|null $ignore_reason
 * @property-read StatementImport $import
 * @property-read JournalLine|null $journalLine
 * @property-read User|null $matcher
 */
#[UsePolicy(StatementLinePolicy::class)]
final class StatementLine extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<StatementImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class, 'statement_import_id');
    }

    /**
     * @return BelongsTo<JournalLine, $this>
     */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function isInflow(): bool
    {
        return $this->amount_poisha->isPositive();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'journal_line_id', 'ignore_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('reconciliation');
    }

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'transacted_on' => 'immutable_date',
            'amount_poisha' => MoneyCast::class,
            'balance_poisha' => MoneyCast::class,
            'status' => StatementLineStatus::class,
            'matched_at' => 'immutable_datetime',
        ];
    }
}
