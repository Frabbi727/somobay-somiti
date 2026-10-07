<?php

declare(strict_types=1);

use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Role;
use App\Models\User;
use App\Support\Time\YearMonth;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    $this->seed([RoleSeeder::class]);
});

afterEach(function (): void {
    DB::reconnect();
    $this->artisan('migrate:fresh');
});

it('creates one member when two presidents approve at the same moment', function (): void {
    approvedPlan('2026-07', '500');
    $application = app(DecideRegistration::class)(userWithRole(Role::Secretary), submittedRegistration(), RegistrationDecisionType::Approve);
    $presidents = [userWithRole(Role::President)->id, userWithRole(Role::President)->id];

    $results = inParallel(2, function (int $index) use ($application, $presidents): string {
        app(DecideRegistration::class)(User::query()->findOrFail($presidents[$index]), MemberApplication::query()->findOrFail($application->id), RegistrationDecisionType::Approve, null, 1, YearMonth::parse('2026-07'));

        return 'approved';
    });

    expect(array_count_values($results)['approved'] ?? 0)->toBe(1)
        ->and(Member::query()->count())->toBe(1);
});
