<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Support\Money\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The portal's Pay Online form, field for field (Filament/Member/Pages/PayOnline).
 */
final class SubmitPaymentRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:bkash,nagad'],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $money = is_string($value) ? Money::tryOfTaka($value) : null;

                if ($money === null || ! $money->isPositive()) {
                    $fail(__('api.errors.amount'));
                }
            }],
            'trx_id' => ['required', 'regex:/^[A-Za-z0-9]{6,40}$/'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'proof' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:'.(int) config('somiti.max_proof_kb')],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
