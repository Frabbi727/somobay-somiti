<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * A member changes their own portal password (the current one is required).
 */
final class ChangeOwnPassword
{
    public const int MIN_LENGTH = 6;

    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw DomainRuleViolation::because('portal.errors.current_password');
        }

        if (mb_strlen($new) < self::MIN_LENGTH) {
            throw DomainRuleViolation::because('members.errors.portal_password_short', ['min' => self::MIN_LENGTH]);
        }

        $this->causer->withCauser($user, function () use ($user, $new): void {
            $user->forceFill(['password' => $new])->save();
            activity('members')->performedOn($user)->log('portal password changed by the member');
        });
    }
}
