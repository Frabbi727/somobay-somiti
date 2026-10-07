<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Data;

use App\Domain\Members\Data\NomineeData;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Bangla\BanglaNumber;
use InvalidArgumentException;

/**
 * What the member changed on their registration. Only the fields present are changed; a draft
 * may be incomplete — the full rules run at submit.
 */
final readonly class RegistrationDraft
{
    /** @var list<string> */
    public const array FIELDS = ['name_bn', 'name_en', 'guardian_name', 'nid', 'date_of_birth', 'email', 'address', 'photo_path', 'requested_shares'];

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

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $text = self::text($input[$field]);

            $attributes[$field] = match ($field) {
                'requested_shares' => is_numeric($input[$field]) ? (int) $input[$field] : null,
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

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
