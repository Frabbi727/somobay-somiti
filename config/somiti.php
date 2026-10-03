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

];
