<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Adds a nominee relation or changes one (labels, order, on/off).
 */
final class SaveNomineeRelation
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, ?NomineeRelation $relation, NomineeRelationData $data): NomineeRelation
    {
        Gate::forUser($actor)->authorize($relation === null ? 'create' : 'update', $relation ?? NomineeRelation::class);

        if ($relation !== null && $data->key !== $relation->key) {
            throw DomainRuleViolation::because('members.errors.relation_key_locked');
        }

        if (preg_match('/^[a-z_]{2,30}$/', $data->key) !== 1) {
            throw DomainRuleViolation::because('members.errors.relation_key_format');
        }

        if ($data->labelBn === '' || $data->labelEn === '') {
            throw DomainRuleViolation::because('members.errors.relation_labels_required');
        }

        return $this->causer->withCauser($actor, fn (): NomineeRelation => DB::transaction(function () use ($relation, $data): NomineeRelation {
            $taken = NomineeRelation::query()->where('key', $data->key)
                ->when($relation !== null, fn ($query) => $query->whereKeyNot($relation?->getKey()))
                ->exists();

            if ($taken) {
                throw DomainRuleViolation::because('members.errors.relation_key_taken', ['key' => $data->key]);
            }

            $locked = $relation === null ? new NomineeRelation : NomineeRelation::query()->whereKey($relation->getKey())->lockForUpdate()->firstOrFail();

            $locked->fill([
                'key' => $data->key,
                'label_bn' => $data->labelBn,
                'label_en' => $data->labelEn,
                'sort' => $data->sort,
                'active' => $data->active,
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
