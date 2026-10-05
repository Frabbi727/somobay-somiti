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
    | Investments (Phase 10)
    |--------------------------------------------------------------------------
    |
    | s.33 limits are shown as warnings when an investment is approved, never as
    | blocks. Each rule: the investment type, the maximum in basis points, and the
    | account whose (credit) balance is the base. The default is the spec's example
    | — company securities up to 10% of the accumulated surplus. Have your auditor
    | confirm these against the Act, the Rules and your bylaws.
    |
    */

    'investment_limits' => [
        ['type' => 'company_securities', 'max_bps' => 1000, 'of_account' => '3901'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Year-end (Phase 11) — Cooperative Societies Act 2001, s.34
    |--------------------------------------------------------------------------
    |
    | Percentages of net profit in basis points, with the legal bounds the wizard
    | enforces. Defaults follow SOMITI_SPEC.md BR-20; HAVE YOUR AUDITOR CONFIRM THEM
    | (and the two choices below) before the first real year-end.
    |
    | - financing_society: the 10% bad/doubtful-debt fund applies to financing
    |   societies; for others it is optional (0–10%).
    | - statutory_base: 'net_profit' takes the percentages of the whole net profit;
    |   'after_loss_offset' takes them of what remains after the s.34(4) offset.
    |
    */

    'year_end' => [
        'financing_society' => (bool) env('SOMITI_FINANCING_SOCIETY', false),
        'statutory_base' => env('SOMITI_STATUTORY_BASE', 'net_profit'),
        'loss_offset_bps' => 5000,
        'reserve' => ['default' => 1500, 'min' => 1500, 'max' => 10000],
        'development_fund' => ['default' => 300, 'min' => 300, 'max' => 300],
        'bad_debt_fund' => ['default' => 0, 'min' => 0, 'max' => 1000, 'financing_min' => 1000],
        'other_funds' => ['default' => 0, 'min' => 0, 'max' => 1000],
    ],

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
    | Member portal and app: members sign in with mobile + the password the secretary sets for them.
    | Switch on to also offer sign-in by SMS code (needs an SMS gateway).
    */

    'portal_otp' => (bool) env('SOMITI_PORTAL_OTP', false),

];
