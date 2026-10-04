<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Models\User;
use App\Policies\StatementImportPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded bank or wallet statement (CSV). Read-only once imported.
 *
 * @property int $id
 * @property PaymentMethod $method
 * @property string $filename
 * @property string $file_path
 * @property string $file_sha256
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property Money|null $closing_balance_poisha
 * @property int $lines_count
 * @property int $duplicates_skipped
 * @property int $imported_by
 * @property CarbonImmutable $created_at
 * @property-read User $importer
 */
#[UsePolicy(StatementImportPolicy::class)]
final class StatementImport extends Model
{
    protected $guarded = [];

    /**
     * @return HasMany<StatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function countWithStatus(StatementLineStatus $status): int
    {
        return $this->lines()->where('status', $status)->count();
    }

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'closing_balance_poisha' => MoneyCast::class,
            'lines_count' => 'integer',
            'duplicates_skipped' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
