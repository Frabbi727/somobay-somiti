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
 * Creates the single draft of the next rules version, or updates it if one already exists.
 */
final class SaveTermsDraft
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, string $rulesBn, string $rulesEn, string $declarationBn, string $declarationEn): MembershipTerms
    {
        Gate::forUser($actor)->authorize('create', MembershipTerms::class);

        $texts = [
            'rules_bn' => trim($rulesBn),
            'rules_en' => trim($rulesEn),
            'declaration_bn' => trim($declarationBn),
            'declaration_en' => trim($declarationEn),
        ];

        if (in_array('', $texts, true)) {
            throw DomainRuleViolation::because('terms.errors.text_required');
        }

        return $this->causer->withCauser($actor, fn (): MembershipTerms => DB::transaction(function () use ($actor, $texts): MembershipTerms {
            $draft = MembershipTerms::query()->where('status', TermsStatus::Draft)->lockForUpdate()->first();

            if ($draft === null) {
                $draft = MembershipTerms::query()->create([...$texts, 'status' => TermsStatus::Draft, 'created_by' => $actor->id]);
            } else {
                $draft->update($texts);
            }

            activity('members')->performedOn($draft)->event('terms_draft_saved')->log('terms draft saved');

            return $draft;
        }, attempts: 3));
    }
}
