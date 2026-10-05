<?php

declare(strict_types=1);

/*
| Laravel's English messages for the rules the app uses (same keys as lang/bn/validation.php), and field names.
*/

return [
    'accepted' => 'The :attribute field must be accepted.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'array' => 'The :attribute field must be an array.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'boolean' => 'The :attribute field must be true or false.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'date' => 'The :attribute field must be a valid date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'email' => 'The :attribute field must be a valid email address.',
    'enum' => 'The selected :attribute is invalid.',
    'file' => 'The :attribute field must be a file.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'integer' => 'The :attribute field must be an integer.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'numeric' => 'The :attribute field must be a number.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'string' => 'The :attribute field must be a string.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uuid' => 'The :attribute field must be a valid UUID.',
    'attributes' => [
        'mobile' => 'mobile number',
        'password' => 'password',
        'current_password' => 'current password',
        'password_confirmation' => 'password confirmation',
        'code' => 'code',
        'refresh_token' => 'refresh token',
        'method' => 'payment method',
        'amount' => 'amount',
        'trx_id' => 'transaction ID (TrxID)',
        'received_on' => 'payment date',
        'proof' => 'payment proof',
        'idempotency_key' => 'submission key',
        'status' => 'status',
        'type' => 'type',
        'from' => 'from date',
        'until' => 'to date',
        'page' => 'page',
    ],
];
