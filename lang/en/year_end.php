<?php

declare(strict_types=1);

return [
    'title' => 'Year-end closing',
    'status' => [
        'draft' => 'Draft — awaiting approval',
        'posted' => 'Posted',
    ],
    'dividend_status' => [
        'unpaid' => 'Unpaid',
        'paid' => 'Paid out',
        'credited' => 'Credited to savings',
    ],
    'fund' => [
        'reserve' => 'Reserve fund',
        'development_fund' => 'Cooperative development fund',
        'bad_debt_fund' => 'Bad & doubtful debt fund',
        'other_funds' => 'Other funds (bylaws)',
    ],
    'errors' => [
        'rate_bounds' => ':fund must be between :min and :max of net profit.',
        'over_appropriated' => 'The appropriations and loss offset add up to more than the net profit.',
        'no_shareholders' => 'There is a dividend to distribute but no member held shares during the year.',
    ],
];
