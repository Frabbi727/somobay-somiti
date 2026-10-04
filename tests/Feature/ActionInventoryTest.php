<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Tests\Support\ActionInventory;

/*
| SOMITI_SPEC.md P7.S2: every action in the staff panel has an icon, tooltip and color, uses the
| §8.1 icon/color for its kind, and asks for the §7 confirmation tier that fits what it does.
| A new action fails here until it is classified below.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

/**
 * The confirmation tier each action must use; null = no confirmation (navigation, downloads).
 * Keys are "Location › name" overrides first, then the action name.
 *
 * @return array<string, string|null>
 */
function expectedTiers(): array
{
    return [
        // Overrides where one name means different things.
        'GenerateDues › run' => 'T3',
        'IntegrityReport › run' => 'T1',
        'PaymentActions › cancel' => 'T1',
        'ListPayments table › cancel' => 'T1',
        'ViewPayment › cancel' => 'T1',
        'ExpenseActions › cancel' => 'T1',
        'ListExpenses table › cancel' => 'T1',
        'ViewExpense › cancel' => 'T1',
        'FundTransferActions › cancel' => 'T1',
        'ListFundTransfers table › cancel' => 'T1',
        'ViewFundTransfer › cancel' => 'T1',
        'CreateStatementImport form › create' => 'T1',
        'InvestmentActions › cancel' => 'T1',
        'ListInvestments table › cancel' => 'T1',
        'ViewInvestment › cancel' => 'T1',
        // Governance records change no money (§7: meetings are T1).
        'CreateMeeting form › create' => 'T1',
        'EditMeeting form › save' => 'T1',
        'MeetingActions › cancel' => 'T1',
        'ViewMeeting › cancel' => 'T1',
        'MeetingActions › hold' => 'T2',
        'ViewMeeting › hold' => 'T2',
        'CreateJournalDraft form › create' => 'T1',
        'EditJournalDraft form › save' => 'T1',

        // T3: financial, destructive or bulk.
        'approve' => 'T3', 'reject' => 'T3', 'reverse' => 'T3', 'post' => 'T3', 'close' => 'T3',
        'unlock' => 'T3', 'waive' => 'T3', 'delete' => 'T3', 'cancel' => 'T3', 'deactivate' => 'T3',
        'refund' => 'T3', 'bulkApprove' => 'T3', 'applyLateFees' => 'T3', 'applyAdvance' => 'T3',

        // T2: member, settings, share and payment entry — show what changes.
        'create' => 'T2', 'save' => 'T2', 'toggleActive' => 'T2', 'submit' => 'T2', 'record' => 'T2',
        'changeShares' => 'T2', 'linkResolution' => 'T2', 'prepare' => 'T2', 'openNextFiscalYear' => 'T2', 'openPreviousFiscalYear' => 'T2',

        // T1: low-risk, reversible.
        'restore' => 'T1', 'duplicate' => 'T1', 'reactivate' => 'T1', 'lock' => 'T1',
        // Statement reconciliation never changes the books.
        'match' => 'T1', 'unmatch' => 'T1', 'ignore' => 'T1', 'automatch' => 'T1',
        'attendance' => 'T1', 'propose' => 'T1', 'withdraw' => 'T1', 'decide' => 'T3',
        'income' => 'T3', 'impair' => 'T3',
        'InvestmentActions › close' => 'T3', 'ViewInvestment › close' => 'T3', 'ListInvestments table › close' => 'T3',

        // No confirmation: navigation and downloads.
        'view' => null, 'edit' => null, 'pdf' => null, 'excel' => null, 'receipt' => null,
        'downloadImpact' => null, 'generate' => null, 'newVoucher' => null, 'collect' => null,
        'meeting' => null,
    ];
}

/**
 * §8.1 icon and color per kind of action.
 *
 * @return array<string, array{0: Heroicon, 1: string}>
 */
function expectedLooks(): array
{
    return [
        'view' => [Heroicon::OutlinedEye, 'gray'],
        'edit' => [Heroicon::OutlinedPencilSquare, 'warning'],
        'approve' => [Heroicon::OutlinedCheckCircle, 'success'],
        'bulkApprove' => [Heroicon::OutlinedCheckCircle, 'success'],
        'reject' => [Heroicon::OutlinedNoSymbol, 'danger'],
        'reverse' => [Heroicon::OutlinedArrowUturnLeft, 'danger'],
        'pdf' => [Heroicon::OutlinedPrinter, 'info'],
        'excel' => [Heroicon::OutlinedArrowDownTray, 'gray'],
        'collect' => [Heroicon::OutlinedBanknotes, 'success'],
        'delete' => [Heroicon::OutlinedTrash, 'danger'],
    ];
}

function tierOf(Action $action): ?string
{
    foreach ((array) ActionInventory::property($action, 'extraAttributes') as $attributes) {
        if (is_array($attributes) && isset($attributes['data-confirm-tier'])) {
            return $attributes['data-confirm-tier'];
        }
    }

    return null;
}

function isFormButton(string $key): bool
{
    return str_contains($key, ' form › ');
}

it('finds the actions of every page, table and action factory', function (): void {
    expect(count(ActionInventory::all()))->toBeGreaterThan(100);
});

it('gives every action an icon, a tooltip and a color', function (): void {
    $problems = [];

    foreach (ActionInventory::all() as $key => $action) {
        if (isFormButton($key)) {
            continue; // labelled submit/cancel buttons under a form
        }

        foreach (['icon', 'tooltip', 'color'] as $property) {
            if (ActionInventory::property($action, $property) === null) {
                $problems[] = "{$key}: no {$property}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('uses the §8.1 icon and color for each kind of action', function (): void {
    $problems = [];

    foreach (ActionInventory::all() as $key => $action) {
        [$icon, $color] = expectedLooks()[$action->getName()] ?? [null, null];

        if ($icon === null || isFormButton($key)) {
            continue;
        }

        if (ActionInventory::property($action, 'icon') !== $icon || ActionInventory::property($action, 'color') !== $color) {
            $problems[] = "{$key}: expected {$icon->value} / {$color}";
        }
    }

    expect($problems)->toBe([]);
});

it('asks for the right confirmation tier on every action', function (): void {
    $problems = [];
    $expected = expectedTiers();

    foreach (ActionInventory::all() as $key => $action) {
        $location = substr($key, 0, (int) strrpos($key, ' › '));
        $name = (string) $action->getName();

        if (isFormButton($key) && $name === 'cancel') {
            continue; // the form's own "Cancel" just leaves the page
        }

        $tierKey = array_key_exists("{$location} › {$name}", $expected) ? "{$location} › {$name}" : $name;

        if (! array_key_exists($tierKey, $expected)) {
            $problems[] = "{$key}: not classified — add it to expectedTiers()";

            continue;
        }

        // Navigating to a create page is not the write itself; the form's create button is.
        $want = $name === 'create' && ! isFormButton($key) ? null : $expected[$tierKey];

        if (tierOf($action) !== $want) {
            $problems[] = sprintf('%s: tier %s, expected %s', $key, tierOf($action) ?? 'none', $want ?? 'none');
        }
    }

    expect($problems)->toBe([]);
});
