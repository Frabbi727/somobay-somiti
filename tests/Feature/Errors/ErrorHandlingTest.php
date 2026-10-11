<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Support\DomainActionRunner;
use App\Support\Errors\ErrorReference;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

/*
| Failures are explained in plain words: whether anything was saved, what to do next, and — when
| support has to look — a short reference that is also in the log.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    $this->actingAs(userWithRole(Role::Accountant));
});

function queryFailure(string $state): QueryException
{
    $previous = new PDOException('SQLSTATE['.$state.']: simulated');
    $previous->errorInfo = [$state, 7, 'simulated'];

    return new QueryException('pgsql', 'UPDATE x', [], $previous);
}

it('shows a business rule as its own message and halts', function (): void {
    expect(fn () => DomainActionRunner::run(fn () => throw DomainRuleViolation::because('payments.errors.not_reversible')))
        ->toThrow(Halt::class);

    Notification::assertNotified(__('payments.errors.not_reversible'));
});

it('explains a missing permission instead of a bare 403', function (): void {
    expect(fn () => DomainActionRunner::run(fn () => throw new AuthorizationException))->toThrow(Halt::class);

    Notification::assertNotified(__('errors.no_permission'));
});

it('says nothing was saved when someone else changed the same rows, with a reference', function (string $state): void {
    expect(fn () => DomainActionRunner::run(fn () => throw queryFailure($state)))->toThrow(Halt::class);

    Notification::assertNotified(__('errors.concurrent_change', ['reference' => ErrorReference::current()]));
})->with(['serialization' => '40001', 'deadlock' => '40P01', 'lock timeout' => '55P03', 'unique key' => '23505']);

it('says nothing was saved when a database rule refuses the change', function (): void {
    expect(fn () => DomainActionRunner::run(fn () => throw queryFailure('23514')))->toThrow(Halt::class);

    Notification::assertNotified(__('errors.rule_blocked', ['reference' => ErrorReference::current()]));
});

it('leaves other database errors to the general error handling', function (): void {
    expect(fn () => DomainActionRunner::run(fn () => throw queryFailure('42P01')))->toThrow(QueryException::class);
});

it('gives every request one reference, kept in the log context', function (): void {
    Route::get('/test-reference', fn () => response()->json([ErrorReference::current(), ErrorReference::current()]))->middleware('web');

    [$first, $second] = $this->get('/test-reference')->json();

    expect($first)->toMatch('/^[A-HJ-NP-Z2-9]{8}$/')
        ->and($second)->toBe($first)
        ->and(Context::get(ErrorReference::KEY))->toBe($first);
});

it('returns the reference with an API server error, never the technical message', function (): void {
    Route::get('/api/test-failure', fn () => throw new RuntimeException('SQL secret details'));

    $response = $this->getJson('/api/test-failure')->assertStatus(500);

    expect($response->json('meta.reference'))->toMatch('/^[A-HJ-NP-Z2-9]{8}$/')
        ->and($response->json('message'))->toContain($response->json('meta.reference'))
        ->and($response->getContent())->not->toContain('SQL secret details');
});

it('shows a friendly page in both languages for errors outside the panels', function (): void {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertSee(__('errors.page.404.title', [], 'bn'))
        ->assertSee(__('errors.page.404.title', [], 'en'));

    $page = view('errors.500')->render();

    expect($page)->toContain(__('errors.page.500.title', [], 'en'))
        ->and($page)->toContain(ErrorReference::current());
});
