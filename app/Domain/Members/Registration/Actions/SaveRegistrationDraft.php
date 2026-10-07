<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Carbon\CarbonImmutable;
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

        if (array_key_exists('requested_shares', $draft->attributes) && (! is_int($shares) || $shares < 1 || $shares > 1000)) {
            throw DomainRuleViolation::because('registration.errors.shares_required');
        }

        $this->assertFormats($draft);

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

    private function assertFormats(RegistrationDraft $draft): void
    {
        $attributes = $draft->attributes;

        $date = $attributes['date_of_birth'] ?? null;

        if (is_string($date)) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Dhaka');

            if ($parsed?->format('Y-m-d') !== $date || $parsed->isAfter(CarbonImmutable::now('Asia/Dhaka'))) {
                throw DomainRuleViolation::because('registration.errors.date_of_birth_invalid');
            }
        }

        $email = $attributes['email'] ?? null;

        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw DomainRuleViolation::because('registration.errors.email_invalid');
        }

        foreach (['name_bn' => 255, 'name_en' => 255, 'guardian_name' => 255, 'email' => 255, 'address' => 1000] as $field => $max) {
            $value = $attributes[$field] ?? null;

            if (is_string($value) && mb_strlen($value) > $max) {
                throw DomainRuleViolation::because('registration.errors.field_too_long');
            }
        }

        $photo = $attributes['photo_path'] ?? null;

        if (is_string($photo) && (! str_starts_with($photo, 'member-photos/') || str_contains($photo, '..'))) {
            throw DomainRuleViolation::because('registration.errors.photo_invalid');
        }

        foreach ($draft->nominees ?? [] as $nominee) {
            $replace = ['name' => $nominee->name];

            if ($nominee->nid !== null && preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nominee->nid) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_nid_format', $replace);
            }

            if ($nominee->mobile !== null && preg_match('/^01[3-9]\d{8}$/', $nominee->mobile) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_mobile_format', $replace);
            }

            if ($nominee->relationId !== null && ! NomineeRelation::isActive($nominee->relationId)) {
                throw DomainRuleViolation::because('members.errors.nominee_relation', $replace);
            }

            if ($nominee->share->value > 10000) {
                throw DomainRuleViolation::because('members.errors.nominee_incomplete');
            }
        }
    }
}
