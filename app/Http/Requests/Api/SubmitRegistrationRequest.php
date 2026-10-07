<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submitting the registration for approval; the key makes a retry harmless.
 */
final class SubmitRegistrationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
