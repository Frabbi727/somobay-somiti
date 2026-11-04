<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Models;

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Policies\OpeningImportPolicy;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * The go-live import: a draft (re-uploaded until it has no errors) or the one posted import.
 *
 * @property int $id
 * @property OpeningImportStatus $status
 * @property YearMonth $go_live_month
 * @property string $file_path
 * @property string $file_name
 * @property string $file_sha256
 * @property array<string, mixed> $payload
 * @property list<array<string, mixed>> $errors
 * @property array<string, mixed> $summary
 * @property int|null $journal_entry_id
 * @property int $uploaded_by
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 */
#[UsePolicy(OpeningImportPolicy::class)]
final class OpeningImport extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $import): void {
            if ($import->getOriginal('status') === OpeningImportStatus::Posted) {
                throw ImmutableRecord::for(self::class, $import->getKey());
            }
        });

        self::deleting(function (self $import): void {
            if ($import->status === OpeningImportStatus::Posted) {
                throw ImmutableRecord::for(self::class, $import->getKey());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OpeningImportStatus::class,
            'go_live_month' => YearMonthCast::class,
            'payload' => 'array',
            'errors' => 'array',
            'summary' => 'array',
            'posted_at' => 'immutable_datetime',
        ];
    }
}
