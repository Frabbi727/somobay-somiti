<?php

declare(strict_types=1);

/*
| The bell 🔔 messages. Each one says what happened and what to do next.
*/

return [
    'open' => 'Open',

    'payment' => [
        'submitted' => ['title' => 'A payment is waiting for your approval', 'body' => ':amount from :reference (:method). Check it, then approve or reject it.'],
        'approved' => ['title' => 'Your payment entry was approved', 'body' => ':amount from :reference is approved and recorded in the books. Nothing more to do.'],
        'rejected' => ['title' => 'Your payment entry was rejected', 'body' => ':amount from :reference was rejected. Reason: :reason. Nothing was recorded in the books; correct it and enter it again if needed.'],
        'reversed' => ['title' => 'A payment was reversed', 'body' => ':amount from :reference was reversed. Reason: :reason. The books and the member’s dues are back as they were before it.'],
    ],

    'member_payment' => [
        'approved' => ['title' => 'Your payment was received', 'body' => 'Your payment of :amount is approved and added to your account.'],
        'rejected' => ['title' => 'Your payment was not accepted', 'body' => 'Your payment of :amount was not accepted. Reason: :reason. Please check the details and pay again, or contact the office.'],
        'reversed' => ['title' => 'Your payment was cancelled', 'body' => 'Your payment of :amount was cancelled by the office. Reason: :reason. Contact the office if you have a question.'],
    ],

    'expense' => [
        'submitted' => ['title' => 'An expense is waiting for your approval', 'body' => ':reference: :amount for :party. Check the bill, then approve or reject it.'],
        'approved' => ['title' => 'Your expense was approved', 'body' => ':reference (:amount) is approved and recorded in the books.'],
        'rejected' => ['title' => 'Your expense was rejected', 'body' => ':reference (:amount) was rejected. Reason: :reason. Nothing was recorded; correct it and enter it again if needed.'],
        'reversed' => ['title' => 'An expense was reversed', 'body' => ':reference (:amount) was reversed. Reason: :reason.'],
    ],

    'transfer' => [
        'submitted' => ['title' => 'A fund transfer is waiting for your approval', 'body' => ':reference: :amount, :party. Check the slip, then approve or reject it.'],
        'approved' => ['title' => 'Your fund transfer was approved', 'body' => ':reference (:amount) is approved and recorded in the books.'],
        'rejected' => ['title' => 'Your fund transfer was rejected', 'body' => ':reference (:amount) was rejected. Reason: :reason. Nothing was recorded.'],
        'reversed' => ['title' => 'A fund transfer was reversed', 'body' => ':reference (:amount) was reversed. Reason: :reason.'],
    ],

    'investment' => [
        'submitted' => ['title' => 'An investment is waiting for your approval', 'body' => ':reference: :amount with :party. Check it, then approve or reject it.'],
        'approved' => ['title' => 'Your investment was approved', 'body' => ':reference (:amount with :party) is approved and recorded in the books.'],
        'rejected' => ['title' => 'Your investment was rejected', 'body' => ':reference (:amount) was rejected. Reason: :reason. Nothing was recorded.'],
    ],

    'rate_plan' => [
        'submitted' => ['title' => 'A rate plan is waiting for your approval', 'body' => ':reference: share unit :amount from :party. Check the impact preview, then approve or send it back.'],
        'approved' => ['title' => 'Your rate plan was approved', 'body' => ':reference is fully approved and applies from :party.'],
        'returned' => ['title' => 'Your rate plan was sent back', 'body' => ':reference was sent back to draft. Read the comment on the plan, correct it and submit it again.'],
    ],

    'year_end' => [
        'submitted' => ['title' => 'A year-end is waiting for your approval', 'body' => 'Year-end :reference is prepared (net profit :amount). Review it, then approve it.'],
        'next_approval' => ['title' => 'A year-end needs your approval to post', 'body' => 'One of the two approvals for year-end :reference is done. Your approval posts it.'],
        'posted' => ['title' => 'The year-end was posted', 'body' => 'Year-end :reference is approved and posted. Dividends can now be paid or credited to savings.'],
    ],

    'exit' => [
        'submitted' => ['title' => 'A member exit is waiting for your approval', 'body' => ':reference: :party wants to leave. Check the settlement, then approve it.'],
        'approved' => ['title' => 'The member exit was approved', 'body' => ':reference (:party) is approved. The settlement of :amount now waits to be paid.'],
        'to_pay' => ['title' => 'An exit settlement is waiting to be paid', 'body' => ':reference: pay :amount to :party, then record the payout.'],
    ],

    'journal_draft' => [
        'submitted' => ['title' => 'A voucher is waiting to be checked and posted', 'body' => ':reference (:amount): :party. Someone other than the person who prepared it must check and post it.'],
        'posted' => ['title' => 'Your voucher was posted', 'body' => ':reference (:amount) was checked and posted to the books.'],
    ],

    'registration' => [
        'submitted' => ['title' => 'A membership registration is waiting for you', 'body' => ':reference (:party) registered as a member. Review the application, then approve, send back or reject it.'],
    ],

    'applicant' => [
        'approved' => ['title' => 'Welcome! Your registration was approved', 'body' => 'You are now a member. Your dues and payments are on your dashboard.'],
        'returned' => ['title' => 'Your registration needs a correction', 'body' => 'Your registration was sent back. Open it to read the reason, correct it and submit it again.'],
    ],

    'access' => [
        'user_roles' => ['title' => 'A staff member’s roles changed', 'body' => ':actor changed the roles of :name: :roles.'],
        'role_permissions' => ['title' => 'Role permissions changed', 'body' => ':actor changed :count permission(s). Check the role permissions page if this was not expected.'],
    ],

    'reminder' => [
        'waiting' => ['title' => 'Items are still waiting for you', 'body' => ':count item(s) have waited for your action for more than :days days. Open your dashboard to deal with them.'],
    ],
];
