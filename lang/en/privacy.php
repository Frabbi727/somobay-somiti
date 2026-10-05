<?php

declare(strict_types=1);

return [
    'title' => 'Privacy Policy',
    'updated' => 'Last updated: 5 October 2026',
    'switch' => 'বাংলায় পড়ুন',
    'link' => 'Privacy policy',
    'intro' => ':name runs the Somobay Somiti member app and the member portal so that members can see their own accounts and report payments. This policy explains what information we keep, why, and the choices you have.',
    'sections' => [
        'collect' => [
            'heading' => 'Information we keep',
            'items' => [
                'Membership details recorded by the society office: your name, guardian\'s name, national ID number, date of birth, mobile number, email address, address, photo and nominees.',
                'Your financial records with the society: monthly dues, payments, advance balance, shares, dividends and statements.',
                'Payments you report in the app or portal: method (bKash/Nagad), amount, transaction ID, date and, if you attach one, a screenshot or PDF as proof. The app shrinks images on your phone before uploading them.',
                'Sign-in details: your mobile number and password (stored only as a one-way hash). The app keeps its sign-in tokens in your phone\'s secure storage.',
                'Technical records kept for security: the time of sign-ins and changes, and in server logs the IP address of requests.',
            ],
        ],
        'use' => [
            'heading' => 'How we use it',
            'items' => [
                'To keep the society\'s accounts and show you your balance, dues, payments and statements.',
                'To check and approve the payments you report.',
                'To send you SMS messages: welcome, monthly dues notices, payment receipts and sign-in codes.',
                'To protect your account and the society\'s records from misuse.',
            ],
        ],
        'share' => [
            'heading' => 'Who can see it',
            'items' => [
                'We do not sell your information or use it for advertising. The app has no advertising or tracking tools.',
                'Society committee members and staff see member records as their role requires; every change is logged.',
                'Our SMS provider receives your mobile number and the text of messages sent to you.',
                'Our hosting provider stores the data on our behalf.',
                'The app downloads its fonts from Google Fonts, which can see your device\'s IP address.',
                'We disclose information when the law or a lawful authority requires it, for example an audit of the society.',
            ],
        ],
        'security' => [
            'heading' => 'How we protect it',
            'items' => [
                'All traffic between the app and our server is encrypted (HTTPS).',
                'Passwords are never stored in readable form. App sign-ins expire and are renewed automatically; signing out ends them on the server.',
                'Financial records cannot be edited after approval; corrections are made as new, recorded entries.',
            ],
        ],
        'retention' => [
            'heading' => 'How long we keep it',
            'items' => [
                'Financial records are kept for as long as the society must keep its accounts for audit and the law; they are not deleted, even after a member leaves.',
                'Other information is kept while you are a member and afterwards only as long as needed for the society\'s records.',
            ],
        ],
        'rights' => [
            'heading' => 'Your choices',
            'items' => [
                'You can see your own records at any time in the app or the member portal.',
                'To correct your details, contact the society office.',
                'To close your app and portal access or ask for your account to be deleted, contact the society office. Your sign-in will be removed; financial records the society must keep by law are retained.',
            ],
        ],
        'children' => [
            'heading' => 'Children',
            'items' => [
                'The app is for members of the society and is not meant for children.',
            ],
        ],
        'changes' => [
            'heading' => 'Changes to this policy',
            'items' => [
                'If we change this policy we will update this page and the date above.',
            ],
        ],
    ],
    'contact' => [
        'heading' => 'Contact',
        'body' => 'For any question about your information, contact :name.',
        'address' => 'Address',
        'phone' => 'Phone',
        'email' => 'Email',
    ],
];
