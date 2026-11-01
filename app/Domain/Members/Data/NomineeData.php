<?php

declare(strict_types=1);

namespace App\Domain\Members\Data;

use App\Domain\Geo\Data\PostalAddress;
use App\Domain\Members\Enums\Gender;
use App\Support\Bangla\BanglaNumber;
use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;

final readonly class NomineeData
{
    public function __construct(
        public string $name,
        public string $relation,
        public Bps $share,
        public ?string $mobile = null,
        public ?string $nid = null,
        public ?int $relationId = null,
        public ?Gender $gender = null,
        public ?string $passportNo = null,
        public ?PostalAddress $presentAddress = null,
        public ?PostalAddress $permanentAddress = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  form row: name, relation_id (or legacy relation), share_percent, mobile, nid, gender, passport_no, address keys
     */
    public static function fromForm(array $data): self
    {
        $mobile = $data['mobile'] ?? null;
        $nid = $data['nid'] ?? null;
        $share = $data['share_percent'] ?? '';
        $relationId = $data['relation_id'] ?? null;
        $gender = $data['gender'] ?? null;
        $passport = $data['passport_no'] ?? null;
        $present = PostalAddress::fromForm($data, 'present');

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            relation: trim((string) ($data['relation'] ?? '')),
            share: $share instanceof Bps ? $share : Bps::ofPercent((string) $share),
            mobile: is_string($mobile) && trim($mobile) !== '' ? (MobileNumber::normalize($mobile) ?? trim($mobile)) : null,
            nid: is_string($nid) && trim($nid) !== '' ? BanglaNumber::toAscii(preg_replace('/\s+/', '', $nid) ?? $nid) : null,
            relationId: is_numeric($relationId) ? (int) $relationId : null,
            gender: $gender instanceof Gender ? $gender : (is_string($gender) ? Gender::tryFrom($gender) : null),
            passportNo: is_string($passport) && trim($passport) !== '' ? strtoupper((string) preg_replace('/\s+/', '', $passport)) : null,
            presentAddress: $present,
            permanentAddress: ($data['permanent_same_as_present'] ?? false) ? $present : PostalAddress::fromForm($data, 'permanent'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paperFormAttributes(): array
    {
        return [
            'gender' => $this->gender,
            'passport_no' => $this->passportNo,
            ...($this->presentAddress ?? new PostalAddress(0, null, ''))->toAttributes('present'),
            ...($this->permanentAddress ?? new PostalAddress(0, null, ''))->toAttributes('permanent'),
        ];
    }
}
