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
    | Security
    |--------------------------------------------------------------------------
    |
    | Staff must set up an authenticator app before using the panel (§2, P7.S2).
    | Switch off only for local development or tests.
    |
    */

    'require_mfa' => (bool) env('SOMITI_REQUIRE_MFA', true),

];
