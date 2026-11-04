<?php

declare(strict_types=1);

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Data\ValidatedOpening;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Support\Time\YearMonth;

function validateOpening(array $members = [], array $society = [], array $investments = []): ValidatedOpening
{
    return app(OpeningImportValidator::class)->validate(new OpeningWorkbook($members ?: [memberRow()], $society, $investments), YearMonth::parse('2026-07'));
}

function errorKeys(ValidatedOpening $result): array
{
    return array_map(fn ($error) => $error->key, $result->errors);
}

it('turns a clean sheet into typed rows, reading Bangla digits and Excel-dropped zeros', function (): void {
    $result = validateOpening([memberRow([
        'mobile' => '1712345678', 'shares' => '৩', 'savings' => '১,০০৫.২৫', 'joined_on' => '২০২০-০৭-০১', 'nid' => '১২৩৪৫৬৭৮৯০',
    ])], [['_row' => '3', 'account_code' => '1101', 'amount' => '5000.50']], [[
        '_row' => '3', 'type' => 'fixed_deposit', 'name' => 'Sonali FDR', 'institution' => 'Sonali Bank', 'instrument_no' => 'FDR-1', 'principal' => '100000',
        'invested_on' => '2025-01-10', 'matures_on' => '2027-01-10', 'expected_rate_percent' => '8.5', 'funded_from' => 'bank',
    ]]);

    expect($result->isClean())->toBeTrue()
        ->and($result->members[0]->member->mobile)->toBe('01712345678')
        ->and($result->members[0]->member->nid)->toBe('1234567890')
        ->and($result->members[0]->member->joinedOn->toDateString())->toBe('2020-07-01')
        ->and($result->members[0]->shares)->toBe(3)
        ->and($result->members[0]->savings->poisha)->toBe(100525)
        ->and($result->society[0]->amount->poisha)->toBe(500050)
        ->and($result->investments[0]->name)->toBe('Sonali FDR')
        ->and($result->investments[0]->expectedRate?->value)->toBe(850);
});

it('reports each problem with its sheet, row and column', function (array $overrides, string $key, string $column): void {
    $result = validateOpening([memberRow([...$overrides, '_row' => '7'])]);

    expect(errorKeys($result))->toContain($key)
        ->and($result->errors[0]->sheet)->toBe('members')
        ->and($result->errors[0]->row)->toBe(7)
        ->and($result->errors[0]->column)->toBe($column)
        ->and($result->members)->toBe([]);
})->with([
    'member no' => [['member_no' => '42'], 'golive.errors.member_no_format', 'member_no'],
    'joined after go-live' => [['joined_on' => '2026-07-01'], 'golive.errors.joined_after_go_live', 'joined_on'],
    'bad date' => [['joined_on' => '01/07/2020'], 'golive.errors.date_format', 'joined_on'],
    'shares' => [['shares' => '0'], 'golive.errors.shares_positive', 'shares'],
    'three decimals' => [['savings' => '1005.2500001'], 'golive.errors.amount_invalid', 'savings'],
    'negative' => [['savings' => '-5'], 'golive.errors.amount_invalid', 'savings'],
    'advance and arrears' => [['advance' => '500', 'arrears_deposit' => '500'], 'golive.errors.advance_and_arrears', 'advance'],
    'mobile' => [['mobile' => '12345'], 'members.errors.mobile_format', 'mobile'],
    'nid' => [['nid' => '123'], 'members.errors.nid_format', 'nid'],
    'names' => [['name_en' => ''], 'members.errors.names_required', 'name_en'],
]);

it('finds duplicates inside the sheet', function (): void {
    $result = validateOpening([
        memberRow(['member_no' => 'M-0100', 'mobile' => '01811111111', 'nid' => '1234567890']),
        memberRow(['member_no' => 'M-0100', 'mobile' => '01811111111', 'nid' => '1234567890']),
    ]);

    expect(errorKeys($result))->toContain('golive.errors.duplicate_member_no', 'golive.errors.duplicate_mobile', 'golive.errors.duplicate_nid')
        ->and($result->members)->toHaveCount(1);
});

it('checks the society and investment sheets', function (): void {
    $result = validateOpening(society: [
        ['_row' => '3', 'account_code' => '2101', 'amount' => '5'],
        ['_row' => '4', 'account_code' => '1101', 'amount' => '5'],
        ['_row' => '5', 'account_code' => '1101', 'amount' => '6'],
    ], investments: [
        ['_row' => '3', 'type' => 'gold', 'name' => '', 'institution' => 'X', 'instrument_no' => '', 'principal' => '0', 'invested_on' => '2026-08-01', 'matures_on' => '', 'expected_rate_percent' => '', 'funded_from' => 'cheque'],
    ]);

    expect(errorKeys($result))->toContain(
        'golive.errors.account_not_allowed', 'golive.errors.duplicate_account', 'golive.errors.investment_type',
        'golive.errors.institution_required', 'golive.errors.amount_positive', 'golive.errors.invested_after_go_live', 'golive.errors.funded_from',
    );
});

it('needs at least one member', function (): void {
    expect(errorKeys(app(OpeningImportValidator::class)->validate(new OpeningWorkbook([], [], []), YearMonth::parse('2026-07'))))
        ->toContain('golive.errors.no_members');
});

it('has a message for every error it can report', function (): void {
    $keys = ['member_no_format', 'duplicate_member_no', 'duplicate_mobile', 'duplicate_nid', 'joined_after_go_live', 'date_format', 'shares_positive',
        'amount_invalid', 'amount_positive', 'advance_and_arrears', 'percent_invalid', 'account_not_allowed', 'duplicate_account', 'investment_type',
        'institution_required', 'invested_after_go_live', 'funded_from', 'no_members'];

    foreach (['en', 'bn'] as $locale) {
        foreach ($keys as $key) {
            expect(__('golive.errors.'.$key, [], $locale))->not->toBe('golive.errors.'.$key);
        }
    }
});
