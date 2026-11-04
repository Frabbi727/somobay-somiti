<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Enums\Permission;
use App\Models\User;
use App\Policies\Concerns\ReadOnlyForStaff;

final class AdvanceLedgerEntryPolicy
{
    use ReadOnlyForStaff;

    /**
     * A refund is reversed by someone other than whoever made it, with the permission.
     */
    public function reverseRefund(User $user, AdvanceLedgerEntry $entry): bool
    {
        return $entry->kind === AdvanceEntryKind::Refund
            && $entry->created_by !== $user->id
            && $user->may(Permission::AdvanceRefundReverse);
    }
}
