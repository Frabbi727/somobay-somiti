<?php

declare(strict_types=1);

return [
    'ok' => 'OK',
    'auth' => [
        'failed' => 'The mobile number or password is not correct.',
        'codes_off' => 'Sign-in with an SMS code is not available.',
        'signed_in' => 'Signed in.',
        'signed_out' => 'Signed out.',
    ],
    'errors' => [
        'amount' => 'Enter an amount greater than zero with up to 2 decimals.',
        'validation' => 'Please check the highlighted fields.',
        'unauthenticated' => 'Please sign in again.',
        'forbidden' => 'You do not have access to this.',
        'not_found' => 'Not found.',
        'method' => 'This action is not allowed here.',
        'throttled' => 'Too many attempts. Please wait a minute and try again.',
        'server' => 'Something went wrong. Please try again later.',
    ],
    'registration' => [
        'member_only' => 'Your registration is not approved yet. Please update the app if you do not see your registration status.',
    ],
];
