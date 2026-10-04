<?php

declare(strict_types=1);

namespace App\Domain\Investments\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Policies\InvestmentLedgerEntryPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Append-only movement of an investment's book value (debit-positive, like its 13xx account).
 *
 * @property int $id
 * @property int $investment_id
 * @property InvestmentEntryKind $kind
 * @property Money $delta_poisha
 * @property int $journal_entry_id
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read JournalEntry $journalEntry
 */
#[UsePolicy(InvestmentLedgerEntryPolicy::class)]
final class InvestmentLedgerEntry extends Model
{
    use LogsActivity;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * Always shown with its voucher (register screen and report).
     *
     * @var list<string>
     */
    protected $with = ['journalEntry'];

    /**
     * @return BelongsTo<Investment, $this>
     */
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => InvestmentEntryKind::class,
            'delta_poisha' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('investments');
    }
}
