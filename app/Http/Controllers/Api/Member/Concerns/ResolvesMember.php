<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member\Concerns;

use App\Domain\Members\Models\Member;
use Illuminate\Http\Request;

/**
 * The signed-in member, as resolved by EnsureMemberAccess from the token.
 */
trait ResolvesMember
{
    protected static function member(Request $request): Member
    {
        $member = $request->attributes->get('member');

        abort_unless($member instanceof Member, 403);

        return $member;
    }
}
