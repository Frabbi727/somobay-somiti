<?php

declare(strict_types=1);

namespace App\Domain\Investments\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Policies\InvestmentIncomePolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $investment_id
 * @property CarbonImmutable $received_on
 * @property PaymentMethod $received_into
 * @property Money $gross_poisha
 * @property Money $tax_deducted_poisha
 * @property string|null $reference
 * @property int $journal_entry_id
 * @property int $created_by
 * @property-read JournalEntry $journalEntry
 */
#[UsePolicy(InvestmentIncomePolicy::class)]
final class InvestmentIncome extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'investment_income';

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

    public function net(): Money
    {
        return $this->gross_poisha->minus($this->tax_deducted_poisha);
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'immutable_date',
            'received_into' => PaymentMethod::class,
            'gross_poisha' => MoneyCast::class,
            'tax_deducted_poisha' => MoneyCast::class,
        ];
    }
}
