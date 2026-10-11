<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Reporting\Data\SubmissionRow;
use App\Domain\Reporting\MySubmissions;
use App\Enums\Permission;
use App\Models\User;
use Filament\Widgets\Widget;

/**
 * "What I entered": the user's latest entries with their status and, while they wait, who can act
 * on them now. Shown to anyone whose work includes entering things for someone else to approve.
 */
final class MySubmissionsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected string $view = 'filament.widgets.my-submissions';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isStaff() && $user->mayAny(
            Permission::PaymentsRecord, Permission::ExpensesRecord, Permission::TransfersRecord, Permission::InvestmentsRecord,
            Permission::RatePlansDraft, Permission::ExitsRequest, Permission::JournalsManage,
        );
    }

    /**
     * @return array{rows: list<SubmissionRow>}
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return ['rows' => $user instanceof User ? app(MySubmissions::class)->recent($user) : []];
    }
}
