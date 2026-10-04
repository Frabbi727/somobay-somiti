<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | PDF reports
    |--------------------------------------------------------------------------
    |
    | mPDF needs a Bangla font whose OpenType tables it can shape. Hind Siliguri
    | (SIL OFL, bundled in resources/fonts) renders conjuncts correctly. Nikosh or
    | another mPDF-compatible font can be dropped into the same folder instead.
    |
    */

    'pdf' => [
        'font_dir' => resource_path('fonts'),
        'font_family' => 'hindsiliguri',
        'font_files' => [
            'R' => 'HindSiliguri-Regular.ttf',
            'B' => 'HindSiliguri-Bold.ttf',
        ],
        'temp_dir' => storage_path('framework/cache/mpdf'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Advance refunds of this many poisha or more need the president (P5.S3).
    |
    */

    'advance_refund_president_threshold_poisha' => (int) env('SOMITI_REFUND_PRESIDENT_THRESHOLD_POISHA', 500_000),

    'max_proof_kb' => 2048,

    /*
    |--------------------------------------------------------------------------
    | Governance (Phase 9)
    |--------------------------------------------------------------------------
    |
    | Quorum is a share of those eligible to attend, in basis points (5000 = half),
    | rounded up to whole people. SET THESE FROM YOUR BYLAWS — the defaults are
    | placeholders, not legal advice.
    |
    | `require_resolution_for` lists the subjects whose approval needs a passed
    | resolution linked to it (e.g. "rate_plan"); comma-separated in the env.
    |
    */

    'quorum_bps' => [
        'committee' => (int) env('SOMITI_QUORUM_COMMITTEE_BPS', 5001),
        'general' => (int) env('SOMITI_QUORUM_GENERAL_BPS', 3334),
        'special_general' => (int) env('SOMITI_QUORUM_SPECIAL_GENERAL_BPS', 3334),
    ],

    'require_resolution_for' => array_values(array_filter(explode(',', (string) env('SOMITI_REQUIRE_RESOLUTION_FOR', '')))),

    /*
    |--------------------------------------------------------------------------
    | Expenses
    |--------------------------------------------------------------------------
    |
    | Expenses of this many poisha or more need the president's approval (§1.3, Phase 8).
    |
    */

    'expense_president_threshold_poisha' => (int) env('SOMITI_EXPENSE_PRESIDENT_THRESHOLD_POISHA', 1_000_000),

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Staff must set up an authenticator app before using the panel (§2, P7.S2).
    | Switch off only for local development or tests.
    |
    */

    'require_mfa' => (bool) env('SOMITI_REQUIRE_MFA', true),

];
