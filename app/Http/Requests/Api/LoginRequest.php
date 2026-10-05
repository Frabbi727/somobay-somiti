<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:20'],
            'password' => ['required_without:code', 'nullable', 'string', 'max:200'],
            'code' => ['required_without:password', 'nullable', 'string', 'max:10'],
        ];
    }
}
