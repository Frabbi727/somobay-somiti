<?php

declare(strict_types=1);

namespace App\Domain\Exits\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Withdraws a request that has not been approved; dues generation resumes for the member.
 */
final class CancelExit
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, MemberExit $exit, string $reason): MemberExit
    {
        if (mb_strlen(trim($reason)) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): MemberExit => DB::transaction(function () use ($actor, $exit, $reason): MemberExit {
            $locked = MemberExit::query()->whereKey($exit->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->update(['status' => ExitStatus::Cancelled, 'cancel_reason' => trim($reason)]);

            return $locked;
        }, attempts: 3));
    }
}
