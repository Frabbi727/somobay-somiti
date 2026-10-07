<?php

declare(strict_types=1);

namespace App\Domain\Members\Data;

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
    ) {}

    /**
     * @param  array<string, mixed>  $data  form row: name, relation_id (or legacy relation), share_percent, mobile, nid
     */
    public static function fromForm(array $data): self
    {
        $mobile = $data['mobile'] ?? null;
        $nid = $data['nid'] ?? null;
        $share = $data['share_percent'] ?? '';
        $relationId = $data['relation_id'] ?? null;

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            relation: trim((string) ($data['relation'] ?? '')),
            share: $share instanceof Bps ? $share : Bps::ofPercent((string) $share),
            mobile: is_string($mobile) && trim($mobile) !== '' ? (MobileNumber::normalize($mobile) ?? trim($mobile)) : null,
            nid: is_string($nid) && trim($nid) !== '' ? BanglaNumber::toAscii(preg_replace('/\s+/', '', $nid) ?? $nid) : null,
            relationId: is_numeric($relationId) ? (int) $relationId : null,
        );
    }
}
