<?php

declare(strict_types=1);

use App\Domain\Geo\Models\GeoUpazila;
use App\Domain\Members\Enums\Gender;
use App\Domain\Members\Enums\RegistrationDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores and casts the new fields', function (): void {
    $upazila = GeoUpazila::query()->firstOrFail();

    $member = Member::factory()->create([
        'gender' => Gender::Female,
        'passport_no' => 'AB1234567',
        'present_upazila_id' => $upazila->id,
        'present_address_line' => 'House 4, Road 2',
        'documents_received' => [RegistrationDocument::Nid, RegistrationDocument::NomineePhoto],
    ])->fresh();

    expect($member->gender)->toBe(Gender::Female)
        ->and($member->present_upazila_id)->toBe($upazila->id)
        ->and($member->documents_received->all())->toBe([RegistrationDocument::Nid, RegistrationDocument::NomineePhoto]);

    $nominee = Nominee::query()->create([
        'member_id' => $member->id, 'name' => 'N', 'relation' => 'x', 'share_bps' => 10000,
        'gender' => Gender::Male,
    ])->fresh();

    expect($nominee->gender)->toBe(Gender::Male);
});

it('defaults the new fields to empty', function (): void {
    $member = Member::factory()->create()->fresh();

    expect($member->gender)->toBeNull()
        ->and($member->documents_received->all())->toBe([]);
});

it('rejects an unknown gender at the database', function (): void {
    $member = Member::factory()->create();

    DB::table('members')->where('id', $member->id)->update(['gender' => 'robot']);
})->throws(QueryException::class);

it('rejects a malformed passport number at the database', function (): void {
    $member = Member::factory()->create();

    DB::table('members')->where('id', $member->id)->update(['passport_no' => 'ab-12']);
})->throws(QueryException::class);
