<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Enums\Role;
use App\Policies\SomitiProfilePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The society's own details, shown on receipts, every report and in both panels. There is one row
 * (id 1); until it is filled in, the application name is used.
 *
 * @property int $id
 * @property string $name_bn
 * @property string $name_en
 * @property string|null $registration_no
 * @property CarbonImmutable|null $registered_on
 * @property string|null $address_bn
 * @property string|null $address_en
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $logo_path
 * @property list<string>|null $registration_approval_roles
 * @property int|null $updated_by
 */
#[UsePolicy(SomitiProfilePolicy::class)]
final class SomitiProfile extends Model
{
    use LogsActivity;

    public const int ID = 1;

    /** Where the logo is kept (private disk; shown inline, never by URL). */
    public const string LOGO_DISK = 'local';

    public const int MAX_LOGO_KB = 512;

    /** Who approves a member's own registration, in order, until the president changes it. */
    public const array DEFAULT_REGISTRATION_CHAIN = ['secretary', 'president'];

    protected $guarded = [];

    /**
     * The saved profile, or an unsaved one named after the application.
     */
    public static function current(): self
    {
        return app()->bound(self::class) ? app(self::class) : tap(
            self::query()->find(self::ID) ?? new self(['name_bn' => (string) config('app.name'), 'name_en' => (string) config('app.name')]),
            fn (self $profile) => app()->instance(self::class, $profile),
        );
    }

    protected static function booted(): void
    {
        self::saved(fn () => app()->forgetInstance(self::class));
    }

    public function displayName(?string $locale = null): string
    {
        $bangla = ($locale ?? app()->getLocale()) === 'bn';
        $name = $bangla ? ($this->name_bn ?: $this->name_en) : ($this->name_en ?: $this->name_bn);

        return $name !== '' ? $name : (string) config('app.name');
    }

    public function displayAddress(?string $locale = null): ?string
    {
        $bangla = ($locale ?? app()->getLocale()) === 'bn';

        return ($bangla ? ($this->address_bn ?: $this->address_en) : ($this->address_en ?: $this->address_bn)) ?: null;
    }

    /**
     * The logo as a data: URI (for the panels and PDFs), or null when there is none.
     */
    public function logoDataUri(): ?string
    {
        if ($this->logo_path === null || ! Storage::disk(self::LOGO_DISK)->exists($this->logo_path)) {
            return null;
        }

        $disk = Storage::disk(self::LOGO_DISK);
        $mime = $disk->mimeType($this->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($this->logo_path));
    }

    /**
     * @return list<Role>
     */
    public function registrationApprovalChain(): array
    {
        $roles = $this->registration_approval_roles ?? self::DEFAULT_REGISTRATION_CHAIN;

        return array_map(fn (string $role): Role => Role::from($role), $roles);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_on' => 'immutable_date',
            'registration_approval_roles' => 'array',
        ];
    }
}
