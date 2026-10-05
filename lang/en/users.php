<?php

declare(strict_types=1);

return [
    'singular' => 'Staff user',
    'plural' => 'Staff users',
    'field' => [
        'name' => 'Name',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'mobile_help' => 'Integrity alerts are sent here by SMS.',
        'locale' => 'Language',
        'roles' => 'Roles',
        'status' => 'Status',
        'password' => 'Password',
        'new_password' => 'New password',
        'new_password_help' => 'Leave empty to keep the current password.',
    ],
    'status' => [
        'active' => 'Active',
        'inactive' => 'Deactivated',
    ],
    'actions' => [
        'create' => 'New staff user',
        'create_heading' => 'Create the account for :name?',
        'save_heading' => 'Save the changes to :name?',
        'deactivate' => 'Deactivate',
        'deactivate_heading' => 'Deactivate :name?',
        'deactivate_description' => 'They will no longer be able to sign in. Everything they recorded stays under their name.',
        'reactivate' => 'Reactivate',
        'reactivate_heading' => 'Let :name sign in again?',
    ],
    'notifications' => [
        'created' => 'Staff account created.',
        'saved' => ':name saved.',
        'deactivated' => ':name can no longer sign in.',
        'reactivated' => ':name can sign in again.',
    ],
    'errors' => [
        'name_required' => 'Enter the name.',
        'email_invalid' => 'Enter a valid email address.',
        'email_taken' => ':email is already used by another account.',
        'mobile_invalid' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
        'staff_role_required' => 'Choose at least one staff role (not "member").',
        'password_required' => 'Set a password for the new account.',
        'password_short' => 'The password must be at least :min characters.',
        'last_super_admin' => 'At least one active super admin must remain.',
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    ],
];
