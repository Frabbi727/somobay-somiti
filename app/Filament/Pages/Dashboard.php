<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Accounting\Models\Expense;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Filament\Pages\Collections\CollectPayment;
use App\Filament\Pages\Dues\GenerateDues;
use App\Filament\Pages\Reports\DefaultersPage;
use App\Filament\Resources\AuditLog\AuditLogResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\Members\MemberResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * The staff home page. The widgets decide for themselves who sees them (waiting for you, my
 * submissions, alerts, the somiti today); the buttons on top are the everyday jobs of this user's
 * roles, each shown only when the user may do it.
 */
final class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $quick = array_values(array_filter([
            $this->link('collect', 'dashboard.quick.collect', Heroicon::OutlinedBanknotes, 'success', CollectPayment::canAccess() ? CollectPayment::getUrl() : null),
            $this->link('newMember', 'dashboard.quick.invite', Heroicon::OutlinedUserPlus, 'primary', $user->can('create', MemberApplication::class) ? MemberApplicationResource::getUrl() : null),
            $this->link('addMember', 'dashboard.quick.add_member', Heroicon::OutlinedUserGroup, 'gray', $user->can('create', Member::class) ? MemberResource::getUrl('create') : null),
            $this->link('recordExpense', 'dashboard.quick.expense', Heroicon::OutlinedReceiptPercent, 'gray', $user->can('create', Expense::class) ? ExpenseResource::getUrl('create') : null),
            $this->link('generateDues', 'dashboard.quick.dues', Heroicon::OutlinedCalendarDays, 'gray', GenerateDues::canAccess() ? GenerateDues::getUrl() : null),
            $this->link('defaulters', 'dashboard.quick.defaulters', Heroicon::OutlinedExclamationTriangle, 'gray', DefaultersPage::canAccess() ? DefaultersPage::getUrl() : null),
            $this->link('auditLog', 'dashboard.quick.audit', Heroicon::OutlinedClipboardDocumentList, 'gray', $user->can('viewAny', AuditEntry::class) ? AuditLogResource::getUrl() : null),
        ]));

        // The first two stay as buttons; the rest fold into "More" so a phone screen stays tidy.
        if (count($quick) <= 3) {
            return $quick;
        }

        return [
            ...array_slice($quick, 0, 2),
            ActionGroup::make(array_slice($quick, 2))
                ->label(__('dashboard.quick.more'))
                ->icon(Heroicon::OutlinedEllipsisHorizontal)
                ->color('gray')
                ->button(),
        ];
    }

    private function link(string $name, string $label, Heroicon $icon, string $color, ?string $url): ?Action
    {
        if ($url === null) {
            return null;
        }

        return Action::make($name)
            ->label(__($label))
            ->tooltip(__($label))
            ->icon($icon)
            ->color($color)
            ->url($url);
    }
}
