<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature, invariant and concurrency tests boot the application against the
| PostgreSQL test database. Unit and arch tests run without the framework.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Invariants');

pest()->extend(TestCase::class)
    ->in('Concurrency');
