<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Policies\AccountPolicy;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_bn
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property bool $is_control
 * @property bool $requires_member
 * @property bool $is_active
 * @property string|null $description
 */
#[UseFactory(AccountFactory::class)]
#[UsePolicy(AccountPolicy::class)]
final class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = [];

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * An account that has ever been posted to is part of the permanent record.
     */
    public function hasJournalLines(): bool
    {
        return $this->journalLines()->exists();
    }

    /**
     * The account name in the current locale, e.g. "1101 · নগদ তহবিল".
     */
    public function displayName(): string
    {
        $name = app()->getLocale() === 'bn' ? $this->name_bn : $this->name_en;

        return $this->code.' · '.$name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('accounting');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_control' => 'boolean',
            'requires_member' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
