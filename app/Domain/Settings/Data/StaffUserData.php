<?php

declare(strict_types=1);

namespace App\Domain\Settings\Data;

use App\Enums\Role;
use App\Support\Contact\MobileNumber;

/**
 * A staff account as entered on the Users screen. The password is only set on create or reset.
 */
final readonly class StaffUserData
{
    /**
     * @param  list<Role>  $roles
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $mobile,
        public array $roles,
        public string $locale = 'bn',
        public ?string $password = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $text = function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $mobile = $text('mobile');
        $roles = [];

        foreach ((array) ($data['roles'] ?? []) as $role) {
            $roles[] = $role instanceof Role ? $role : Role::from((string) $role);
        }

        return new self(
            name: $text('name') ?? '',
            email: mb_strtolower($text('email') ?? ''),
            mobile: $mobile === null ? null : (MobileNumber::normalize($mobile) ?? $mobile),
            roles: array_values(array_unique($roles, SORT_REGULAR)),
            locale: in_array($text('locale'), ['bn', 'en'], true) ? (string) $text('locale') : 'bn',
            password: is_string($data['password'] ?? null) && $data['password'] !== '' ? $data['password'] : null,
        );
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_map(fn (Role $role): string => $role->value, $this->roles);
    }
}
