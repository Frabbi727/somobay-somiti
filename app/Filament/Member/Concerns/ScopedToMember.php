<?php

declare(strict_types=1);

namespace App\Filament\Member\Concerns;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Models\User;

/**
 * Every portal page shows only the signed-in member's own records.
 */
trait ScopedToMember
{
    protected static function member(): Member
    {
        $user = auth()->user();
        $member = $user instanceof User ? app(PortalAccounts::class)->activeMemberOf($user) : null;

        abort_if($member === null, 403);

        return $member;
    }
}
