<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates or updates a manual voucher draft. Drafts may be unbalanced; posting validates them.
 */
final class SaveJournalDraft
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, ?JournalDraft $draft, JournalDraftData $data): JournalDraft
    {
        Gate::forUser($actor)->authorize($draft === null ? 'create' : 'update', $draft ?? JournalDraft::class);

        if (! in_array($data->type, VoucherType::manual(), true)) {
            throw DomainRuleViolation::because('journal.errors.type_not_manual', ['type' => $data->type->getLabel()]);
        }

        if ($data->narration === '') {
            throw DomainRuleViolation::because('journal.errors.narration_required');
        }

        return $this->causer->withCauser($actor, fn (): JournalDraft => DB::transaction(function () use ($actor, $draft, $data): JournalDraft {
            $attributes = [
                'voucher_type' => $data->type,
                'entry_date' => $data->entryDate->toDateString(),
                'narration' => $data->narration,
                'lines' => $data->lines,
            ];

            if ($draft === null) {
                return JournalDraft::query()->create([...$attributes, 'created_by' => $actor->id]);
            }

            $locked = JournalDraft::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw DomainRuleViolation::because('journal.errors.draft_posted', ['id' => $locked->id]);
            }

            $locked->fill($attributes)->save();

            return $locked;
        }, attempts: 3));
    }
}
