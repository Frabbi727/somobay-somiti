<?php

declare(strict_types=1);

namespace App\Filament\Member\Concerns;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;

/**
 * Registration pages exist only for people still registering, and show only their own registration.
 */
trait ScopedToApplicant
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AccountTypes::class)->of($user) === AccountType::Applicant;
    }

    protected static function application(): MemberApplication
    {
        $user = auth()->user();
        $application = $user instanceof User ? app(AccountTypes::class)->openApplicationOf($user) : null;

        abort_if($application === null, 403);

        return $application;
    }
}
