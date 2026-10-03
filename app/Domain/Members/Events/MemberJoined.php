<?php

declare(strict_types=1);

namespace App\Domain\Members\Events;

use App\Domain\Members\Models\Member;

final readonly class MemberJoined
{
    public function __construct(public Member $member) {}
}
