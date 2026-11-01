<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * A member accepts the current version of the rules for themselves. Accepting again, or retrying
 * with the same key, answers with the acceptance already stored.
 */
final class AcceptTerms
{
    public function __construct(
        private readonly TermsGate $gate,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Member $member, TermsConsent $consent): TermsAcceptance
    {
        Gate::forUser($actor)->authorize('accept', [TermsAcceptance::class, $member]);

        return $this->causer->withCauser($actor, fn (): TermsAcceptance => DB::transaction(function () use ($actor, $member, $consent): TermsAcceptance {
            Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $byKey = TermsAcceptance::query()->where('idempotency_key', $consent->idempotencyKey)->first();

            if ($byKey !== null) {
                return $byKey;
            }

            $current = $this->gate->assertCurrent($consent->version);

            $existing = $this->gate->currentAcceptance($member);

            if ($existing !== null) {
                return $existing;
            }

            $acceptance = TermsAcceptance::query()->create([
                'terms_id' => $current->getKey(),
                'member_id' => $member->getKey(),
                'user_id' => $actor->id,
                'channel' => $consent->channel,
                'ip' => $consent->ip,
                'idempotency_key' => $consent->idempotencyKey,
                'accepted_at' => now(),
            ]);

            activity('members')->performedOn($member)->event('terms_accepted')
                ->withProperties(['version' => $current->version, 'channel' => $consent->channel->value])->log('terms accepted');

            return $acceptance;
        }, attempts: 3));
    }
}
