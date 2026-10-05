<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Illuminate\Support\Facades\Hash;

/**
 * Who a mobile number + password (or SMS code) belongs to — one check for the portal and the app.
 * Only members who have not exited can sign in.
 */
final class MemberCredentials
{
    public function __construct(
        private readonly PortalAccounts $accounts,
        private readonly LoginCodes $codes,
    ) {}

    public function byPassword(string $mobile, string $password): ?User
    {
        $member = $this->member($mobile);

        if ($member === null) {
            return null;
        }

        $user = $this->accounts->forMember($member);

        return Hash::check($password, $user->password) ? $user : null;
    }

    /**
     * @throws DomainRuleViolation when the code is wrong, used up or expired
     */
    public function byCode(string $mobile, string $code): ?User
    {
        $member = $this->codes->verify($mobile, $code);

        return $member->status === MemberStatus::Exited ? null : $this->accounts->forMember($member);
    }

    private function member(string $mobile): ?Member
    {
        $normalized = MobileNumber::normalize($mobile);

        return $normalized === null ? null : Member::query()
            ->where('mobile', $normalized)
            ->where('status', '!=', MemberStatus::Exited)
            ->first();
    }
}
