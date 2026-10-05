<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Members\Portal\ChangeOwnPassword;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:'.ChangeOwnPassword::MIN_LENGTH, 'max:200', 'confirmed'],
        ];
    }
}
