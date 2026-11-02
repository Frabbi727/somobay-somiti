<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Support;

use App\Domain\Geo\Data\PostalAddress;
use App\Domain\Geo\Services\GeoDirectory;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Enums\RegistrationDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use App\Domain\Members\Models\NomineeRelation;
use App\Filament\Support\Display;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;

final class MemberPresenter
{
    /**
     * @return array<string, string>
     */
    public static function summaryLabels(bool $withShares = false): array
    {
        return [
            'name_bn' => __('members.member.name_bn'),
            'name_en' => __('members.member.name_en'),
            'guardian_name' => __('members.member.guardian_name'),
            'nid' => __('members.member.nid'),
            'date_of_birth' => __('members.member.date_of_birth'),
            'mobile' => __('members.member.mobile'),
            'email' => __('members.member.email'),
            'address' => __('members.member.address'),
            'gender' => __('members.member.gender'),
            'passport_no' => __('members.member.passport_no'),
            'present_address' => __('members.address.present'),
            'permanent_address' => __('members.address.permanent'),
            'documents_received' => __('members.member.documents_received'),
            'joined_on' => __('members.member.joined_on'),
            ...($withShares ? [
                'shares' => __('members.member.shares'),
                'effective_from' => __('members.member.effective_from'),
            ] : []),
            'nominees' => __('members.member.nominees_section'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summaryValues(?MemberData $data, ?int $shares = null, ?YearMonth $from = null): array
    {
        if ($data === null) {
            return [];
        }

        return [
            'name_bn' => $data->nameBn,
            'name_en' => $data->nameEn,
            'guardian_name' => $data->guardianName,
            'nid' => $data->nid === null ? null : Display::digits($data->nid),
            'date_of_birth' => $data->dateOfBirth,
            'mobile' => Display::digits($data->mobile),
            'email' => $data->email,
            'address' => $data->address,
            'gender' => $data->gender?->getLabel(),
            'passport_no' => $data->passportNo,
            'present_address' => self::postal($data->presentAddress),
            'permanent_address' => self::postal($data->permanentAddress),
            'documents_received' => $data->documentsReceived === [] ? null : implode(', ', array_map(fn (RegistrationDocument $document): string => (string) $document->getLabel(), $data->documentsReceived)),
            'joined_on' => $data->joinedOn,
            'shares' => $shares === null ? null : Display::digits($shares),
            'effective_from' => $from,
            'nominees' => self::nominees($data->nominees),
        ];
    }

    public static function fromMember(Member $member): MemberData
    {
        return new MemberData(
            nameBn: $member->name_bn,
            nameEn: $member->name_en,
            mobile: $member->mobile,
            joinedOn: $member->joined_on,
            guardianName: $member->guardian_name,
            nid: $member->nid,
            dateOfBirth: $member->date_of_birth,
            email: $member->email,
            address: $member->address,
            photoPath: $member->photo_path,
            gender: $member->gender,
            passportNo: $member->passport_no,
            presentAddress: PostalAddress::fromAttributes($member, 'present'),
            permanentAddress: PostalAddress::fromAttributes($member, 'permanent'),
            documentsReceived: array_values($member->documents_received->all()),
            nominees: array_values($member->nominees->map(fn (Nominee $nominee): NomineeData => new NomineeData(
                $nominee->name,
                $nominee->relation,
                Bps::of($nominee->share_bps),
                $nominee->mobile,
                $nominee->nid,
                $nominee->relation_id,
            ))->all()),
        );
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function dataFromState(?array $state): ?MemberData
    {
        try {
            return MemberData::fromForm($state ?? []);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function postal(?PostalAddress $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $place = $address->upazilaId > 0 ? app(GeoDirectory::class)->describe($address->upazilaId, $address->unionId) : '';
        $text = trim(implode(', ', array_filter([$address->line, $place], fn (string $part): bool => $part !== '')));

        return $text === '' ? null : $text;
    }

    /**
     * "Karim (Son) 60%, Salma (Wife) 40%". The relation label comes from relation_id when set (the form
     * only carries the id), else the legacy free-text relation, so unchanged nominees render identically.
     *
     * @param  list<NomineeData>  $nominees
     */
    private static function nominees(array $nominees): ?string
    {
        if ($nominees === []) {
            return null;
        }

        $ids = array_values(array_filter(array_map(fn (NomineeData $nominee): ?int => $nominee->relationId, $nominees)));
        $relations = $ids === [] ? collect() : NomineeRelation::query()->whereKey($ids)->get()->keyBy('id');

        return implode(', ', array_map(
            fn (NomineeData $nominee): string => sprintf(
                '%s (%s) %s',
                $nominee->name,
                $relations->get($nominee->relationId)?->label() ?? $nominee->relation,
                $nominee->share->format(app()->getLocale()),
            ),
            $nominees,
        ));
    }
}
