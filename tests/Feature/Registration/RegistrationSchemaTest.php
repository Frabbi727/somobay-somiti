<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationDecision;
use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function rawApplication(string $mobile = '01811111111', string $status = 'invited'): MemberApplication
{
    $user = User::factory()->create();

    return MemberApplication::query()->create([
        'user_id' => $user->id, 'mobile' => $mobile, 'status' => $status, 'invited_by' => $user->id,
    ]);
}

it('allows one open registration per mobile, any number of closed ones', function (): void {
    rawApplication('01811111111', 'rejected');
    rawApplication('01811111111', 'invited');

    expect(fn () => rawApplication('01811111111', 'submitted'))->toThrow(QueryException::class);
});

it('checks status and mobile in the database', function (): void {
    $inviter = User::factory()->create();

    expect(fn () => DB::table('member_applications')->insert([
        'user_id' => $inviter->id, 'mobile' => '01811111111', 'status' => 'pending', 'invited_by' => $inviter->id,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => rawApplication('0181111111', 'invited'))->toThrow(QueryException::class);
});

it('never changes or deletes a decision', function (): void {
    $application = rawApplication();
    $decision = MemberApplicationDecision::query()->create([
        'application_id' => $application->id, 'submission_no' => 1, 'step' => 0, 'role' => Role::Secretary,
        'user_id' => $application->user_id, 'decision' => 'approve',
    ]);

    expect(fn () => $decision->update(['reason' => 'x']))->toThrow(ImmutableRecord::class)
        ->and(fn () => DB::table('member_application_decisions')->where('id', $decision->id)->delete())->toThrow(QueryException::class);
});

it('needs a reason to return or reject', function (): void {
    $application = rawApplication();

    expect(fn () => MemberApplicationDecision::query()->create([
        'application_id' => $application->id, 'submission_no' => 1, 'step' => 0, 'role' => Role::Secretary,
        'user_id' => $application->user_id, 'decision' => 'return',
    ]))->toThrow(QueryException::class);
});

it('never deletes an application', function (): void {
    expect(fn () => rawApplication()->delete())->toThrow(ImmutableRecord::class);
});

it('defaults the approval chain to secretary then president', function (): void {
    expect(SomitiProfile::current()->registrationApprovalChain())->toBe([Role::Secretary, Role::President]);
});

it('tells the member what to do next', function (): void {
    $application = rawApplication();

    expect($application->nextAction()->value)->toBe('complete');

    $application->status = MemberApplicationStatus::Returned;
    expect($application->nextAction()->value)->toBe('resubmit');

    $application->status = MemberApplicationStatus::Submitted;
    expect($application->nextAction()->value)->toBe('wait');

    $application->status = MemberApplicationStatus::Rejected;
    expect($application->nextAction()->value)->toBe('none');
});

it('checks the nominee NID format in the database', function (): void {
    $application = rawApplication();

    expect(fn () => DB::table('member_application_nominees')->insert([
        'application_id' => $application->id, 'name' => 'Nominee', 'nid' => '123', 'share_bps' => 10000,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('seeds the registration SMS templates with timestamps', function (): void {
    $templates = DB::table('sms_templates')->whereIn('key', ['registration_returned', 'registration_rejected'])->get();

    expect($templates)->toHaveCount(2)
        ->and($templates->every(fn (object $row): bool => $row->created_at !== null && $row->updated_at !== null && (bool) $row->is_active))->toBeTrue()
        ->and($templates->firstWhere('key', 'registration_returned')?->body_en)->toContain('{reason}');
});
