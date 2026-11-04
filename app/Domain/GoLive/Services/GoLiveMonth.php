<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Support\Time\YearMonth;

/**
 * The month the society started using the app (from the posted go-live import), or null.
 */
final class GoLiveMonth
{
    public function get(): ?YearMonth
    {
        return OpeningImport::query()->where('status', OpeningImportStatus::Posted)->first()?->go_live_month;
    }
}
