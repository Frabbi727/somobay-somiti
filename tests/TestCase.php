<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // The Filament/Livewire tests leave cyclic garbage behind; freeing it keeps the whole suite under the 512M limit.
        gc_collect_cycles();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never need the compiled frontend (CI does not run npm).
        $this->withoutVite();
    }
}
