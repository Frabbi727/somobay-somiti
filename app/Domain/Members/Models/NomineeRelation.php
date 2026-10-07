<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Policies\NomineeRelationPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A relation a nominee can have to the member (father, spouse, …). Never deleted — nominees point
 * at it; switch it off instead.
 *
 * @property int $id
 * @property string $key
 * @property string $label_bn
 * @property string $label_en
 * @property int $sort
 * @property bool $active
 */
#[UsePolicy(NomineeRelationPolicy::class)]
final class NomineeRelation extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::deleting(fn (self $relation) => throw ImmutableRecord::for(self::class, $relation->getKey()));
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'bn' ? $this->label_bn : $this->label_en;
    }

    /**
     * Active relations for a select, id => label in the current language.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->where('active', true)->orderBy('sort')->orderBy('id')->get()
            ->mapWithKeys(fn (self $relation): array => [$relation->id => $relation->label()])
            ->all();
    }

    public static function isActive(?int $id): bool
    {
        return $id !== null && self::query()->whereKey($id)->where('active', true)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort' => 'integer', 'active' => 'boolean'];
    }
}
