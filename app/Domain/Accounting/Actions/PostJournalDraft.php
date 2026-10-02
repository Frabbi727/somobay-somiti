<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Turns a draft into a numbered, immutable journal entry.
 */
final class PostJournalDraft
{
    public function __construct(private readonly PostJournal $post) {}

    public function __invoke(User $actor, JournalDraft $draft): JournalEntry
    {
        Gate::forUser($actor)->authorize('post', $draft);

        return DB::transaction(function () use ($actor, $draft): JournalEntry {
            $locked = JournalDraft::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw DomainRuleViolation::because('journal.errors.draft_posted', ['id' => $locked->id]);
            }

            $data = new JournalDraftData(
                type: $locked->voucher_type,
                entryDate: $locked->entry_date,
                narration: $locked->narration,
                lines: $locked->lines,
            );

            $entry = ($this->post)($actor, new JournalEntryData(
                type: $data->type,
                entryDate: $data->entryDate,
                narration: $data->narration,
                lines: $data->journalLines(),
                source: $locked,
            ));

            $locked->journal_entry_id = $entry->id;
            $locked->save();

            return $entry;
        }, attempts: 3);
    }
}
