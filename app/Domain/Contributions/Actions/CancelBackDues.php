<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Contributions\Enums\DueOrigin;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes a wrong late-joiner bill (design 2026-11-22 §2): cancels every open back-due and the
 * entry premium of a member, only while none of them has any money applied, and only by someone
 * other than the person who created the member. The dues start month itself never changes.
 */
final class CancelBackDues
{
    public function __construct(private readonly CauserResolver $causer) {}

    /**
     * @return int the number of dues cancelled
     */
    public function __invoke(User $actor, Member $member, string $reason): int
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw DomainRuleViolation::because('dues.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): int => DB::transaction(function () use ($actor, $member, $reason): int {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('cancelBackDues', $locked);

            if ($locked->created_by === $actor->id) {
                throw DomainRuleViolation::because('dues.errors.cancel_own_back_dues');
            }

            $dues = Due::query()
                ->where('member_id', $locked->id)
                ->where('origin', DueOrigin::BackDues)
                ->where('status', DueStatus::Open)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($dues->isEmpty()) {
                throw DomainRuleViolation::because('dues.errors.no_back_dues');
            }

            if ($dues->contains(fn (Due $due): bool => $due->paid_poisha->isPositive())) {
                throw DomainRuleViolation::because('dues.errors.back_dues_paid');
            }

            $now = CarbonImmutable::now();

            foreach ($dues as $due) {
                $due->forceFill(['status' => DueStatus::Cancelled, 'note' => trim($reason), 'closed_at' => $now])->save();
            }

            activity('dues')->performedOn($locked)->causedBy($actor)->event('back_dues_cancelled')
                ->withProperties([
                    'reason' => trim($reason),
                    'count' => $dues->count(),
                    'amount_poisha' => $dues->sum(fn (Due $due): int => $due->amount_poisha->poisha),
                ])
                ->log('back_dues_cancelled');

            return $dues->count();
        }, attempts: 3));
    }
}
