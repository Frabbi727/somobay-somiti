<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Filament\Resources\AuditLog\AuditLogResource;
use App\Filament\Resources\AuditLog\Pages\ListAuditEntries;
use App\Filament\Resources\AuditLog\Pages\ViewAuditEntry;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| The audit log: every create/update/delete and sign-in is recorded, nothing in it can change,
| and only roles with "see the audit log" can read it.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('records who changed which field of a record, from which address', function (): void {
    $secretary = userWithRole(Role::Secretary);
    $this->actingAs($secretary);
    travelTo('2026-07-05');
    approvedPlan('2026-07', '500');
    $member = onboard(1, '2026-07');

    $this->get('/'); // a request, so the address is known
    $member->forceFill(['address' => 'New address'])->save();

    $entry = AuditEntry::query()->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->where('event', 'updated')->latest('id')->firstOrFail();

    expect($entry->attribute_changes?->get('attributes'))->toMatchArray(['address' => 'New address'])
        ->and($entry->causer_id)->toBe($secretary->id)
        ->and($entry->properties?->get('ip'))->toBe('127.0.0.1');
});

it('records records that used to go unlogged, but never passwords', function (): void {
    $user = userWithRole(Role::Cashier);
    $user->forceFill(['name' => 'Renamed', 'password' => 'new-secret-123'])->save();

    $entry = AuditEntry::query()->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->where('event', 'updated')->sole();

    expect($entry->attribute_changes?->get('attributes'))->toBe(['name' => 'Renamed'])
        ->and(json_encode($entry->attribute_changes))->not->toContain('new-secret-123');
});

it('records sign-ins, sign-outs and failed sign-ins without the password', function (): void {
    $user = userWithRole(Role::Accountant);
    $user->forceFill(['email' => 'acc@somiti.test', 'password' => 'right-password'])->save();

    expect(auth()->attempt(['email' => 'acc@somiti.test', 'password' => 'wrong-password']))->toBeFalse()
        ->and(auth()->attempt(['email' => 'acc@somiti.test', 'password' => 'right-password']))->toBeTrue();
    auth()->logout();

    $events = AuditEntry::query()->where('log_name', 'auth')->orderBy('id')->pluck('event')->all();

    expect($events)->toBe(['sign_in_failed', 'signed_in', 'signed_out'])
        ->and(AuditEntry::query()->where('event', 'sign_in_failed')->sole()->properties?->get('login'))->toBe('acc@somiti.test')
        ->and(AuditEntry::query()->where('log_name', 'auth')->get()->toJson())->not->toContain('wrong-password');
});

it('can never be changed or deleted', function (): void {
    activity()->log('something happened');
    $entry = AuditEntry::query()->latest('id')->firstOrFail();

    expect(fn () => $entry->forceFill(['description' => 'edited'])->save())->toThrow(ImmutableRecord::class)
        ->and(fn () => $entry->delete())->toThrow(ImmutableRecord::class);

    DB::beginTransaction();
    expect(fn () => DB::table('activity_log')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
    DB::rollBack();
});

it('lets the president, super admin and auditor read the log, and nobody else', function (Role $role, bool $allowed): void {
    $this->actingAs(userWithRole($role))->get(AuditLogResource::getUrl())->assertStatus($allowed ? 200 : 403);
})->with([
    [Role::President, true], [Role::SuperAdmin, true], [Role::Auditor, true],
    [Role::Cashier, false], [Role::Accountant, false], [Role::Secretary, false],
]);

it('lists, filters and shows an entry with its changes', function (): void {
    $auditor = userWithRole(Role::Auditor);
    $cashier = userWithRole(Role::Cashier);
    $cashier->forceFill(['name' => 'Kashem'])->save();
    $this->actingAs($auditor);

    $change = AuditEntry::query()->where('subject_id', $cashier->id)->where('event', 'updated')->sole();
    $other = AuditEntry::query()->where('event', 'signed_in')->first();

    Livewire::test(ListAuditEntries::class)
        ->assertCanSeeTableRecords([$change])
        ->filterTable('event', 'updated')
        ->assertCanSeeTableRecords([$change]);

    Livewire::test(ViewAuditEntry::class, ['record' => $change->getKey()])
        ->assertSee('Kashem')
        ->assertSee(__('audit.event.updated'));

    expect($other)->toBeNull();
});
