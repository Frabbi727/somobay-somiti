<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Actions;

use App\Domain\Members\Terms\Enums\TermsStatus;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Publishes the draft as the next version. Members accept the new version on their next visit.
 */
final class PublishTerms
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, MembershipTerms $draft): MembershipTerms
    {
        return $this->causer->withCauser($actor, fn (): MembershipTerms => DB::transaction(function () use ($actor, $draft): MembershipTerms {
            $locked = MembershipTerms::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TermsStatus::Draft) {
                throw DomainRuleViolation::because('terms.errors.not_draft');
            }

            Gate::forUser($actor)->authorize('publish', $locked);

            $nextVersion = (int) MembershipTerms::query()->max('version') + 1;

            $locked->update([
                'version' => $nextVersion,
                'status' => TermsStatus::Published,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            activity('members')->performedOn($locked)->event('terms_published')
                ->withProperties(['version' => $nextVersion])->log('terms published');

            return $locked;
        }, attempts: 3));
    }
}
