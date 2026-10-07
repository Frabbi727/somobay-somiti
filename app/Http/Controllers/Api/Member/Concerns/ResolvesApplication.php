<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member\Concerns;

use App\Domain\Members\Registration\Models\MemberApplication;
use Illuminate\Http\Request;

/**
 * The signed-in person's open registration, as resolved by EnsureApplicantAccess from the token.
 */
trait ResolvesApplication
{
    protected static function application(Request $request): MemberApplication
    {
        $application = $request->attributes->get('application');

        abort_unless($application instanceof MemberApplication, 403);

        return $application;
    }
}
