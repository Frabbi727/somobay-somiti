<?php

declare(strict_types=1);

namespace App\Domain\Members\Data;

use App\Domain\Geo\Data\PostalAddress;
use App\Domain\Members\Enums\Gender;
use App\Domain\Members\Enums\RegistrationDocument;
use App\Support\Bangla\BanglaNumber;
use App\Support\Contact\MobileNumber;
use Carbon\CarbonImmutable;

/**
 * A member's personal details and nominees (everything except shares).
 */
final readonly class MemberData
{
    /**
     * @param  list<NomineeData>  $nominees
     * @param  list<RegistrationDocument>  $documentsReceived
     */
    public function __construct(
        public string $nameBn,
        public string $nameEn,
        public string $mobile,
        public CarbonImmutable $joinedOn,
        public ?string $guardianName = null,
        public ?string $nid = null,
        public ?CarbonImmutable $dateOfBirth = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $photoPath = null,
        public array $nominees = [],
        public ?Gender $gender = null,
        public ?string $passportNo = null,
        public ?PostalAddress $presentAddress = null,
        public ?PostalAddress $permanentAddress = null,
        public array $documentsReceived = [],
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

        $mobile = $text('mobile') ?? '';
        $nid = $text('nid');
        $dob = $text('date_of_birth');
        $joined = $text('joined_on');
        $nominees = is_array($data['nominees'] ?? null) ? $data['nominees'] : [];
        $present = PostalAddress::fromForm($data, 'present');
        $passport = $text('passport_no');
        $gender = $data['gender'] ?? null;

        return new self(
            nameBn: $text('name_bn') ?? '',
            nameEn: $text('name_en') ?? '',
            mobile: MobileNumber::normalize($mobile) ?? $mobile,
            joinedOn: $joined === null ? CarbonImmutable::today() : CarbonImmutable::parse($joined),
            guardianName: $text('guardian_name'),
            nid: $nid === null ? null : BanglaNumber::toAscii(preg_replace('/\s+/', '', $nid) ?? $nid),
            dateOfBirth: $dob === null ? null : CarbonImmutable::parse($dob),
            email: $text('email'),
            address: $text('address'),
            photoPath: $text('photo_path'),
            nominees: self::nominees($nominees),
            gender: $gender instanceof Gender ? $gender : (is_string($gender) ? Gender::tryFrom($gender) : null),
            passportNo: $passport === null ? null : strtoupper((string) preg_replace('/\s+/', '', $passport)),
            presentAddress: $present,
            permanentAddress: ($data['permanent_same_as_present'] ?? false) ? $present : PostalAddress::fromForm($data, 'permanent'),
            documentsReceived: self::documents($data['documents_received'] ?? []),
        );
    }

    /**
     * @return list<RegistrationDocument>
     */
    private static function documents(mixed $values): array
    {
        $documents = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $document = $value instanceof RegistrationDocument ? $value : (is_string($value) ? RegistrationDocument::tryFrom($value) : null);

            if ($document !== null && ! in_array($document, $documents, true)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * Rows with no name are ignored, so an empty repeater row never becomes a nominee.
     *
     * @param  array<mixed>  $rows
     * @return list<NomineeData>
     */
    private static function nominees(array $rows): array
    {
        $nominees = [];

        foreach ($rows as $row) {
            if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
                $nominees[] = NomineeData::fromForm($row);
            }
        }

        return $nominees;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name_bn' => $this->nameBn,
            'name_en' => $this->nameEn,
            'guardian_name' => $this->guardianName,
            'nid' => $this->nid,
            'date_of_birth' => $this->dateOfBirth?->toDateString(),
            'mobile' => $this->mobile,
            'email' => $this->email,
            'address' => $this->address,
            'photo_path' => $this->photoPath,
            'joined_on' => $this->joinedOn->toDateString(),
            'gender' => $this->gender,
            'passport_no' => $this->passportNo,
            'documents_received' => $this->documentsReceived,
            ...($this->presentAddress ?? new PostalAddress(0, null, ''))->toAttributes('present'),
            ...($this->permanentAddress ?? new PostalAddress(0, null, ''))->toAttributes('permanent'),
        ];
    }
}
