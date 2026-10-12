<?php

declare(strict_types=1);

/*
| Messages for failures that are not a business rule. Each says what happened, whether anything
| was saved, and what to do next.
*/

return [
    'no_permission' => 'You do not have permission to do this, or it is no longer waiting for you. Nothing was saved. Refresh the page to see its current status, and contact your somiti administrator if you think you should have access.',
    'concurrent_change' => 'Someone else was changing the same record at that moment, so nothing was saved. Refresh the page, check its current status, then try again if it is still needed. (Reference: :reference)',
    'rule_blocked' => 'The books refused this change, so nothing was saved. Please tell your administrator this reference: :reference.',
    'preview_unavailable' => 'This preview could not be worked out. Check the month and the number of shares.',

    'panel' => [
        'title' => 'Something went wrong',
        'body' => 'The page could not finish your request. Refresh the page and check whether your change was saved before trying again. If it keeps happening, tell your administrator the time it happened.',
        'forbidden_title' => 'You do not have permission',
        'forbidden_body' => 'Your access may have changed. Refresh the page, and contact your somiti administrator if you think you should have access.',
        'expired_title' => 'The page has expired',
        'expired_body' => 'You were away for a while, so nothing was saved. Refresh the page and try again.',
        'throttled_title' => 'Too many attempts',
        'throttled_body' => 'Please wait a minute, then try again.',
        'unavailable_title' => 'The system is being updated',
        'unavailable_body' => 'Please try again in a few minutes. Nothing was saved.',
    ],

    'page' => [
        'reference' => 'Reference for support: :reference',
        'home' => 'Go to the start page',
        'portal' => 'Open the member portal',
        'sign_out' => 'Sign out',
        'back' => 'Go back',
        '403' => ['title' => 'You do not have permission', 'body' => 'This page is not part of your work in the somiti. If you think you should see it, contact your somiti administrator.'],
        '404' => ['title' => 'Page not found', 'body' => 'The page may have moved, or the link is wrong. Use the menu to find what you need.'],
        '419' => ['title' => 'The page has expired', 'body' => 'You were away for a while, so nothing was saved. Go back, refresh the page and try again.'],
        '429' => ['title' => 'Too many attempts', 'body' => 'Please wait a minute, then try again.'],
        '500' => ['title' => 'Something went wrong on our side', 'body' => 'Your request could not be finished. Before trying again, check whether your change was saved, so nothing is entered twice. If it keeps happening, tell your administrator the reference below.'],
        '503' => ['title' => 'The system is being updated', 'body' => 'Please try again in a few minutes.'],
    ],
];
