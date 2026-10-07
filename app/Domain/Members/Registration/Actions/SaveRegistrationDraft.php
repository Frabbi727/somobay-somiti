<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The member saves (part of) their own registration — before the first submit or after it was
 * sent back. The applicant is always the actor, taken from their sign-in.
 */
final class SaveRegistrationDraft
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(MemberApplication $application, RegistrationDraft $draft): MemberApplication
    {
        $nid = $draft->attributes['nid'] ?? null;

        if (is_string($nid) && preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nid) !== 1) {
            throw DomainRuleViolation::because('members.errors.nid_format');
        }

        $shares = $draft->attributes['requested_shares'] ?? null;

        if (array_key_exists('requested_shares', $draft->attributes) && (! is_int($shares) || $shares < 1)) {
            throw DomainRuleViolation::because('registration.errors.shares_required');
        }

        return $this->causer->withCauser($application->user, fn (): MemberApplication => DB::transaction(function () use ($application, $draft): MemberApplication {
            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw DomainRuleViolation::because('registration.errors.not_editable');
            }

            $oldPhoto = $locked->photo_path;
            $locked->fill($draft->attributes)->save();

            if ($oldPhoto !== null && $oldPhoto !== $locked->photo_path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPhoto));
            }

            if ($draft->nominees !== null) {
                $locked->nominees()->delete();

                foreach ($draft->nominees as $index => $nominee) {
                    $locked->nominees()->create([
                        'name' => $nominee->name,
                        'relation_id' => $nominee->relationId,
                        'mobile' => $nominee->mobile,
                        'nid' => $nominee->nid,
                        'share_bps' => $nominee->share->value,
                        'sort' => $index,
                    ]);
                }
            }

            activity('members')->performedOn($locked)->event('registration_saved')->log('registration saved');

            return $locked->load('nominees');
        }, attempts: 3));
    }
}
