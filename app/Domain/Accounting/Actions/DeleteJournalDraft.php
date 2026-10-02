<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Soft-deletes an unposted draft (§7.4). A posted draft is part of the voucher's history.
 */
final class DeleteJournalDraft
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, JournalDraft $draft): void
    {
        $this->causer->withCauser($actor, fn () => DB::transaction(function () use ($actor, $draft): void {
            $locked = JournalDraft::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw DomainRuleViolation::because('journal.errors.draft_posted', ['id' => $locked->id]);
            }

            Gate::forUser($actor)->authorize('delete', $locked);

            $locked->delete();
        }, attempts: 3));
    }
}
