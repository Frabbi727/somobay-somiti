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
        'forbidden' => 'You do not have access to this. Contact the somiti office if you think you should.',
        'not_found' => 'This was not found. It may have been removed; refresh and try again.',
        'method' => 'This action is not allowed here.',
        'throttled' => 'Too many attempts. Please wait a minute and try again.',
        'server' => 'Something went wrong on our side. Before trying again, check whether your request was saved. If it keeps happening, tell the office this reference: :reference.',
    ],
    'registration' => [
        'member_only' => 'Your registration is not approved yet. Please update the app if you do not see your registration status.',
    ],
];
