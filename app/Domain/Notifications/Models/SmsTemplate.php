<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Policies\SmsTemplatePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property SmsTemplateKey $key
 * @property string $body_bn
 * @property string $body_en
 * @property bool $is_active
 */
#[UsePolicy(SmsTemplatePolicy::class)]
final class SmsTemplate extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['key' => SmsTemplateKey::class, 'is_active' => 'boolean'];
    }
}
