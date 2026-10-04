<?php

declare(strict_types=1);

return [
    'title' => 'Integrity Report',
    'run_now' => 'Run checks now',
    'run_now_tooltip' => 'Re-check the books now instead of waiting for tonight',
    'run_now_description' => 'All integrity checks will run in the background. Nothing in the books is changed.',
    'queued' => 'Integrity checks queued. The result will appear here shortly.',
    'never_run' => 'The integrity checks have not run yet.',
    'no_findings' => 'No problems found',
    'summary' => 'Last run :time · :checks checks · :findings findings',
    'columns' => [
        'check' => 'Check',
        'message' => 'Finding',
        'run' => 'Run',
    ],
    'status' => [
        'running' => 'Running',
        'passed' => 'All clear',
        'failed' => 'Problems found',
    ],
    'checks' => [
        'balanced_entries' => 'Balanced entries',
        'control_accounts' => 'Control accounts',
        'payment_allocations' => 'Payment allocations',
        'due_paid_amounts' => 'Due paid amounts',
        'advance_chain' => 'Advance balances',
        'voucher_sequences' => 'Voucher numbering',
        'due_snapshots' => 'Rate snapshots',
        'journal_hash_chain' => 'Tamper check (hash chain)',
        'expense_postings' => 'Expense postings',
        'transfer_postings' => 'Fund transfer postings',
        'statement_matches' => 'Statement matches',
        'investment_postings' => 'Investment register',
        'year_end_totals' => 'Year-end totals',
        'exited_members_clear' => 'Exited members cleared',
    ],
    'banner' => [
        'title' => 'Integrity check failed.',
        'body' => ':count problem(s) found in the run of :time. Do not close any period until this is resolved.',
        'link' => 'View findings',
    ],
    'alert' => [
        'subject' => 'Somiti integrity check failed',
        'body' => ':count problem(s) were found in the books. Please review the integrity report.',
        'open' => 'Open integrity report',
    ],
];
