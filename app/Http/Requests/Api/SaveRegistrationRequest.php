<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * One step of the member's own registration form; every field optional (partial saves),
 * each checked for its format. The complete rules run when the member submits.
 */
final class SaveRegistrationRequest extends FormRequest
{
    private const string NID = '/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nid' => ['sometimes', 'nullable', 'string', 'regex:'.self::NID],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'requested_shares' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'nominees' => ['sometimes', 'array', 'max:10'],
            'nominees.*.name' => ['required', 'string', 'max:255'],
            'nominees.*.relation_id' => ['nullable', 'integer', Rule::exists('nominee_relations', 'id')->where('active', true)],
            'nominees.*.nid' => ['nullable', 'string', 'regex:'.self::NID],
            'nominees.*.mobile' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && MobileNumber::normalize($value) === null) {
                    $fail(__('members.errors.mobile_format'));
                }
            }],
            'nominees.*.share_percent' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    Bps::ofPercent((string) $value);
                } catch (InvalidArgumentException) {
                    $fail(__('money.validation.invalid'));
                }
            }],
        ];
    }
}
