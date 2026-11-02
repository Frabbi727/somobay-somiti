<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Data;

use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Enums\Gender;
use App\Domain\Members\Enums\MaritalStatus;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Bangla\BanglaNumber;
use BackedEnum;
use InvalidArgumentException;

/**
 * What the member changed on their registration. Only the fields present are changed; a draft
 * may be incomplete — the full rules run at submit.
 */
final readonly class RegistrationDraft
{
    /** @var list<string> */
    public const array FIELDS = ['name_bn', 'name_en', 'guardian_name', 'father_name', 'mother_name', 'profession', 'marital_status', 'nid', 'date_of_birth', 'email', 'address', 'photo_path', 'requested_shares', 'gender', 'passport_no', 'present_upazila_id', 'present_union_id', 'present_address_line', 'permanent_upazila_id', 'permanent_union_id', 'permanent_address_line'];

    /**
     * @param  array<string, string|int|null>  $attributes  column => value, only for fields that were sent
     * @param  list<NomineeData>|null  $nominees  null = nominees not sent (keep them)
     */
    public function __construct(public array $attributes, public ?array $nominees = null) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $attributes = [];

        if (filter_var($input['permanent_same_as_present'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            foreach (['upazila_id', 'union_id', 'address_line'] as $part) {
                if (array_key_exists('present_'.$part, $input)) {
                    $input['permanent_'.$part] = $input['present_'.$part];
                }
            }
        }

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $text = self::text($input[$field]);

            $attributes[$field] = match ($field) {
                'requested_shares', 'present_upazila_id', 'present_union_id', 'permanent_upazila_id', 'permanent_union_id' => self::wholeNumber($input[$field]),
                'gender' => $text === null ? null : Gender::tryFrom($text)?->value,
                'marital_status' => $text === null ? null : MaritalStatus::tryFrom($text)?->value,
                'passport_no' => $text === null ? null : strtoupper((string) preg_replace('/\s+/', '', $text)),
                'nid' => $text === null ? null : BanglaNumber::toAscii(preg_replace('/\s+/', '', $text) ?? $text),
                default => $text,
            };
        }

        return new self($attributes, is_array($input['nominees'] ?? null) ? self::nominees($input['nominees']) : null);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<NomineeData>
     */
    private static function nominees(array $rows): array
    {
        $nominees = [];

        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }

            $share = $row['share_percent'] ?? '';

            try {
                $nominees[] = NomineeData::fromForm([...$row, 'share_percent' => is_scalar($share) && trim((string) $share) !== '' ? (string) $share : '0']);
            } catch (InvalidArgumentException) {
                throw DomainRuleViolation::because('members.errors.nominee_incomplete');
            }
        }

        return $nominees;
    }

    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $ascii = BanglaNumber::toAscii(trim($value));

        return preg_match('/^\d{1,9}$/', $ascii) === 1 ? (int) $ascii : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
