<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
use App\Domain\Members\Models\ShareTransaction;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/*
| SOMITI_SPEC.md §3.7 / P7.S2: a policy on every model; financial records are never deleted.
*/

/**
 * @return list<class-string<Model>>
 */
function allModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in([app_path('Domain'), app_path('Models')])->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getRealPath(), strlen(app_path()) + 1));

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}

it('has a policy for every model', function (): void {
    $models = allModels();
    $missing = array_values(array_filter($models, fn (string $model): bool => Gate::getPolicyFor($model) === null));

    expect(count($models))->toBeGreaterThan(20)
        ->and($missing)->toBe([]);
});

it('never lets anyone delete a financial record, not even the super admin', function (string $model): void {
    $admin = userWithRole(Role::SuperAdmin);
    $record = new $model;

    expect($admin->can('delete', $record))->toBeFalse()
        ->and($admin->can('forceDelete', $record))->toBeFalse()
        ->and($admin->can('update', $record))->toBeFalse();
})->with([
    JournalEntry::class, JournalLine::class, Payment::class, PaymentAllocation::class,
    Due::class, AdvanceLedgerEntry::class, ShareTransaction::class,
]);

it('lets only the super admin manage staff accounts, and never delete them', function (): void {
    $admin = userWithRole(Role::SuperAdmin);
    $staff = userWithRole(Role::Cashier);

    expect($admin->can('create', User::class))->toBeTrue()
        ->and($admin->can('update', $staff))->toBeTrue()
        ->and($admin->can('delete', $staff))->toBeFalse()
        ->and(userWithRole(Role::President)->can('viewAny', User::class))->toBeFalse();
});
