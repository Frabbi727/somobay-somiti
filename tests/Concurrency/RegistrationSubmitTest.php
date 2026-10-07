<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Models\MemberApplication;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    $this->seed([RoleSeeder::class]);
});

afterEach(function (): void {
    DB::reconnect();
    $this->artisan('migrate:fresh');
});

it('submits once when the same submit arrives four times at once', function (): void {
    $applicationId = completeRegistration(invite())->id;
    $key = (string) Str::uuid();

    $results = inParallel(4, function () use ($applicationId, $key): string {
        $application = app(SubmitRegistration::class)(MemberApplication::query()->findOrFail($applicationId), $key);

        return 'submission '.$application->submission_no;
    });

    expect(array_unique($results))->toBe(['submission 1'])
        ->and(MemberApplication::query()->findOrFail($applicationId)->submission_no)->toBe(1);
});
