<?php

declare(strict_types=1);

use App\Domain\Members\Models\Member;
use App\Domain\Members\Terms\Actions\PublishTerms;
use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Enums\TermsStatus;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function saveDraft(?User $actor = null, string $rulesBn = 'নিয়ম ১'): MembershipTerms
{
    return app(SaveTermsDraft::class)($actor ?? userWithRole(Role::President), $rulesBn, '1. Rule', 'ঘোষণা', 'Declaration');
}

it('seeds version 1 as current', function (): void {
    $current = MembershipTerms::current();

    expect($current->version)->toBe(1)
        ->and($current->status)->toBe(TermsStatus::Published)
        ->and($current->rules_bn)->toContain('২৪.')
        ->and($current->rules_en)->toContain('24.')
        ->and($current->published_by)->toBeNull();
});

it('publishing a draft makes it current with the next version', function (): void {
    $president = userWithRole(Role::President);
    $draft = saveDraft($president);

    expect($draft->version)->toBeNull();

    $published = app(PublishTerms::class)($president, $draft);

    expect($published->version)->toBe(2)
        ->and(MembershipTerms::current()->is($published))->toBeTrue()
        ->and($published->published_by)->toBe($president->id)
        ->and(MembershipTerms::draft())->toBeNull()
        ->and(Activity::query()->where('event', 'terms_published')->count())->toBe(1);
});

it('keeps a single draft and updates it on save', function (): void {
    $first = saveDraft();
    $second = saveDraft(null, 'নিয়ম ২');

    expect($second->is($first))->toBeTrue()
        ->and(MembershipTerms::query()->where('status', 'draft')->count())->toBe(1)
        ->and($second->fresh()->rules_bn)->toBe('নিয়ম ২');
});

it('only one draft at a time in the database', function (): void {
    saveDraft();

    expect(fn () => DB::table('membership_terms')->insert([
        'status' => 'draft', 'rules_bn' => 'a', 'rules_en' => 'a', 'declaration_bn' => 'a', 'declaration_en' => 'a',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires all four texts', function (): void {
    expect(fn () => app(SaveTermsDraft::class)(userWithRole(Role::President), 'a', 'b', ' ', 'd'))
        ->toThrow(DomainRuleViolation::class);
});

it('published terms cannot change', function (): void {
    $current = MembershipTerms::current();

    expect(fn () => $current->update(['rules_en' => 'changed']))->toThrow(ImmutableRecord::class)
        ->and(fn () => $current->delete())->toThrow(ImmutableRecord::class)
        ->and(fn () => DB::table('membership_terms')->where('id', $current->id)->update(['rules_en' => 'changed']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('membership_terms')->where('id', $current->id)->delete())->toThrow(QueryException::class);
});

it('cannot publish a version that is already published', function (): void {
    expect(fn () => app(PublishTerms::class)(userWithRole(Role::President), MembershipTerms::current()))
        ->toThrow(DomainRuleViolation::class);
});

it('needs the publish permission', function (): void {
    $draft = saveDraft();

    expect(fn () => app(PublishTerms::class)(userWithRole(Role::Cashier), $draft))->toThrow(AuthorizationException::class)
        ->and(fn () => saveDraft(userWithRole(Role::Cashier)))->toThrow(AuthorizationException::class);
});

function insertAcceptance(array $subject): int
{
    return DB::table('terms_acceptances')->insertGetId([
        'terms_id' => MembershipTerms::current()->id,
        'user_id' => userWithRole(Role::President)->id,
        'channel' => 'portal',
        'idempotency_key' => (string) Str::uuid(),
        'accepted_at' => now(),
        ...['member_id' => null, 'application_id' => null],
        ...$subject,
    ]);
}

it('acceptances need exactly one subject', function (): void {
    expect(fn () => insertAcceptance([]))->toThrow(QueryException::class)
        ->and(fn () => insertAcceptance(['member_id' => Member::factory()->create()->id, 'application_id' => 1]))->toThrow(QueryException::class);
});

it('acceptances are insert-only', function (): void {
    $member = Member::factory()->create();
    $id = insertAcceptance(['member_id' => $member->id]);

    expect(fn () => TermsAcceptance::query()->findOrFail($id)->update(['channel' => 'app']))->toThrow(ImmutableRecord::class)
        ->and(fn () => insertAcceptance(['member_id' => $member->id]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('terms_acceptances')->where('id', $id)->update(['channel' => 'app']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('terms_acceptances')->where('id', $id)->delete())->toThrow(QueryException::class);
});
