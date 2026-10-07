<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Services\RegistrationTimeline;
use App\Enums\Role;

beforeEach(function (): void {
    app()->setLocale('en');
    approvedPlan('2026-07', '500');
});

/**
 * @return list<string> "key:state"
 */
function shape($application): array
{
    return array_map(fn (TimelineStep $step): string => $step->key.':'.$step->state->value, app(RegistrationTimeline::class)->steps($application));
}

it('shows the configured chain before the first submit', function (): void {
    $application = invite();

    expect(shape($application))->toBe(['submitted:pending', 'step_0:waiting', 'step_1:waiting', 'activation:waiting'])
        ->and(app(RegistrationTimeline::class)->headline($application))->toBe('Your registration is incomplete')
        ->and(app(RegistrationTimeline::class)->steps($application)[1]->label)->toBe('Secretary approval');
});

it('marks done, pending and waiting steps while approvals run', function (): void {
    $application = submittedRegistration();
    expect(shape($application))->toBe(['submitted:done', 'step_0:pending', 'step_1:waiting', 'activation:waiting'])
        ->and(app(RegistrationTimeline::class)->headline($application))->toBe('Secretary approval pending');

    $application = app(DecideRegistration::class)(userWithRole(Role::Secretary), $application, RegistrationDecisionType::Approve);
    expect(shape($application))->toBe(['submitted:done', 'step_0:done', 'step_1:pending', 'activation:waiting'])
        ->and(app(RegistrationTimeline::class)->message($application))->toContain('approved by the Secretary');

    $application = approveRegistration($application);
    expect(shape($application))->toBe(['submitted:done', 'step_0:done', 'step_1:done', 'activation:done']);
});

it('shows who sent it back and why', function (): void {
    $application = app(DecideRegistration::class)(userWithRole(Role::Secretary), submittedRegistration(), RegistrationDecisionType::Return, 'NID photo unclear');

    $steps = app(RegistrationTimeline::class)->steps($application);
    $decision = app(RegistrationTimeline::class)->decision($application);

    expect(shape($application))->toBe(['submitted:done', 'step_0:returned', 'step_1:waiting', 'activation:waiting'])
        ->and($steps[1]->reason)->toBe('NID photo unclear')
        ->and($decision?->reason)->toBe('NID photo unclear')
        ->and(app(RegistrationTimeline::class)->headline($application))->toBe('Please correct your registration');
});
