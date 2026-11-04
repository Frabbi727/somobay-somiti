<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\GoLive\Data\OpeningImportError;
use App\Domain\GoLive\Data\OpeningInvestmentRow;
use App\Domain\GoLive\Data\OpeningMemberRow;
use App\Domain\GoLive\Data\OpeningSocietyRow;
use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Data\ValidatedOpening;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Support\Bangla\BanglaNumber;
use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;
use App\Support\Money\Exceptions\InvalidMoneyAmount;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

/**
 * Checks every row of the go-live workbook and returns typed rows plus a list of errors (sheet,
 * row, column, message). Nothing is thrown for bad data, so staff see every problem at once.
 *
 * Members are checked for identity only (names, mobile, NID, duplicates). The paper-form profile
 * (gender, addresses) and nominees are completed on the member page after posting, where the full
 * member rules apply.
 */
final class OpeningImportValidator
{
    public const array DEBIT_CODES = ['1101', '1111', '1121', '1122'];

    public const array CREDIT_CODES = ['2211', '3101', '3201', '3202', '3203'];

    /** @var list<OpeningImportError> */
    private array $errors = [];

    public function validate(OpeningWorkbook $book, YearMonth $goLive): ValidatedOpening
    {
        $this->errors = [];

        if ($book->members === []) {
            $this->error('members', 0, null, 'golive.errors.no_members');
        }

        $members = $this->members($book->members, $goLive);
        $society = $this->society($book->society);
        $investments = $this->investments($book->investments, $goLive);

        return new ValidatedOpening($members, $society, $investments, $this->errors);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<OpeningMemberRow>
     */
    private function members(array $rows, YearMonth $goLive): array
    {
        $valid = [];
        $seen = ['member_no' => [], 'mobile' => [], 'nid' => []];

        foreach ($rows as $raw) {
            $row = (int) $this->field($raw, '_row');
            $before = count($this->errors);

            $memberNo = strtoupper($this->field($raw, 'member_no'));

            if (preg_match('/^M-\d{4,}$/', $memberNo) !== 1) {
                $this->error('members', $row, 'member_no', 'golive.errors.member_no_format');
            } elseif (isset($seen['member_no'][$memberNo])) {
                $this->error('members', $row, 'member_no', 'golive.errors.duplicate_member_no', ['no' => $memberNo]);
            }

            $seen['member_no'][$memberNo] = true;

            $nameBn = $this->field($raw, 'name_bn');
            $nameEn = $this->field($raw, 'name_en');

            if ($nameBn === '' || $nameEn === '') {
                $this->error('members', $row, $nameBn === '' ? 'name_bn' : 'name_en', 'members.errors.names_required');
            }

            $mobile = $this->mobile($this->field($raw, 'mobile'));

            if (preg_match('/^01[3-9]\d{8}$/', $mobile) !== 1) {
                $this->error('members', $row, 'mobile', 'members.errors.mobile_format');
            } elseif (isset($seen['mobile'][$mobile])) {
                $this->error('members', $row, 'mobile', 'golive.errors.duplicate_mobile', ['mobile' => $mobile]);
            } elseif (Member::withTrashed()->where('mobile', $mobile)->exists()) {
                $this->error('members', $row, 'mobile', 'members.errors.mobile_taken', ['mobile' => $mobile]);
            }

            $seen['mobile'][$mobile] = true;

            $nidText = $this->field($raw, 'nid');
            $nid = $nidText === '' ? null : BanglaNumber::toAscii(preg_replace('/\s+/', '', $nidText) ?? $nidText);

            if ($nid !== null) {
                if (preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nid) !== 1) {
                    $this->error('members', $row, 'nid', 'members.errors.nid_format');
                } elseif (isset($seen['nid'][$nid])) {
                    $this->error('members', $row, 'nid', 'golive.errors.duplicate_nid');
                } elseif (Member::withTrashed()->where('nid', $nid)->exists()) {
                    $this->error('members', $row, 'nid', 'members.errors.nid_taken');
                }

                $seen['nid'][$nid] = true;
            }

            $joined = $this->date('members', $row, 'joined_on', $this->field($raw, 'joined_on'), required: true);

            if ($joined !== null && YearMonth::fromDate($joined)->isSameOrAfter($goLive)) {
                $this->error('members', $row, 'joined_on', 'golive.errors.joined_after_go_live', ['month' => (string) $goLive]);
            }

            $sharesText = BanglaNumber::toAscii($this->field($raw, 'shares'));
            $shares = ctype_digit($sharesText) ? (int) $sharesText : 0;

            if ($shares < 1) {
                $this->error('members', $row, 'shares', 'golive.errors.shares_positive');
            }

            $savings = $this->money('members', $row, 'savings', $this->field($raw, 'savings'));
            $advance = $this->money('members', $row, 'advance', $this->field($raw, 'advance'));
            $arrearsDeposit = $this->money('members', $row, 'arrears_deposit', $this->field($raw, 'arrears_deposit'));
            $arrearsFees = $this->money('members', $row, 'arrears_fees', $this->field($raw, 'arrears_fees'));

            if ($advance->isPositive() && ($arrearsDeposit->isPositive() || $arrearsFees->isPositive())) {
                $this->error('members', $row, 'advance', 'golive.errors.advance_and_arrears');
            }

            if (count($this->errors) === $before && $joined !== null) {
                $valid[] = new OpeningMemberRow(
                    row: $row,
                    memberNo: $memberNo,
                    member: new MemberData(nameBn: $nameBn, nameEn: $nameEn, mobile: $mobile, joinedOn: $joined, nid: $nid),
                    shares: $shares,
                    savings: $savings,
                    advance: $advance,
                    arrearsDeposit: $arrearsDeposit,
                    arrearsFees: $arrearsFees,
                );
            }
        }

        return $valid;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<OpeningSocietyRow>
     */
    private function society(array $rows): array
    {
        $valid = [];
        $seen = [];

        foreach ($rows as $raw) {
            $row = (int) $this->field($raw, '_row');
            $before = count($this->errors);
            $code = BanglaNumber::toAscii($this->field($raw, 'account_code'));

            if (! in_array($code, [...self::DEBIT_CODES, ...self::CREDIT_CODES], true)) {
                $this->error('society', $row, 'account_code', 'golive.errors.account_not_allowed', ['code' => $code]);
            } elseif (in_array($code, $seen, true)) {
                $this->error('society', $row, 'account_code', 'golive.errors.duplicate_account', ['code' => $code]);
            }

            $seen[] = $code;

            $amount = $this->money('society', $row, 'amount', $this->field($raw, 'amount'));

            if (count($this->errors) === $before) {
                $valid[] = new OpeningSocietyRow($row, $code, $amount);
            }
        }

        return $valid;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<OpeningInvestmentRow>
     */
    private function investments(array $rows, YearMonth $goLive): array
    {
        $valid = [];

        foreach ($rows as $raw) {
            $row = (int) $this->field($raw, '_row');
            $before = count($this->errors);

            $type = InvestmentType::tryFrom($this->field($raw, 'type'));

            if ($type === null) {
                $this->error('investments', $row, 'type', 'golive.errors.investment_type');
            }

            $institution = $this->field($raw, 'institution');

            if (mb_strlen($institution) < 2) {
                $this->error('investments', $row, 'institution', 'golive.errors.institution_required');
            }

            $principal = $this->money('investments', $row, 'principal', $this->field($raw, 'principal'));

            if ($principal->isZero()) {
                $this->error('investments', $row, 'principal', 'golive.errors.amount_positive');
            }

            $investedOn = $this->date('investments', $row, 'invested_on', $this->field($raw, 'invested_on'), required: true);

            if ($investedOn !== null && ! $investedOn->isBefore($goLive->firstDay())) {
                $this->error('investments', $row, 'invested_on', 'golive.errors.invested_after_go_live');
            }

            $maturesOn = $this->date('investments', $row, 'matures_on', $this->field($raw, 'matures_on'), required: false);

            if ($maturesOn !== null && $investedOn !== null && ! $maturesOn->isAfter($investedOn)) {
                $this->error('investments', $row, 'matures_on', 'investments.errors.maturity');
            }

            $rate = null;
            $rateText = BanglaNumber::toAscii($this->field($raw, 'expected_rate_percent'));

            if ($rateText !== '') {
                try {
                    $rate = Bps::ofPercent($rateText);
                } catch (InvalidMoneyAmount) {
                    $this->error('investments', $row, 'expected_rate_percent', 'golive.errors.percent_invalid');
                }
            }

            $fundedFrom = PaymentMethod::tryFrom($this->field($raw, 'funded_from'));

            if ($fundedFrom === null) {
                $this->error('investments', $row, 'funded_from', 'golive.errors.funded_from');
            }

            if (count($this->errors) === $before && $type !== null && $investedOn !== null && $fundedFrom !== null) {
                $instrument = $this->field($raw, 'instrument_no');

                $valid[] = new OpeningInvestmentRow(
                    row: $row,
                    type: $type,
                    name: $this->field($raw, 'name'),
                    institution: $institution,
                    instrumentNo: $instrument === '' ? null : $instrument,
                    principal: $principal,
                    investedOn: $investedOn,
                    maturesOn: $maturesOn,
                    expectedRate: $rate,
                    fundedFrom: $fundedFrom,
                );
            }
        }

        return $valid;
    }

    /**
     * Blank = 0. More than 2 decimals or a negative amount is an error (never rounded).
     */
    private function money(string $sheet, int $row, string $column, string $text): Money
    {
        if ($text === '') {
            return Money::zero();
        }

        $money = Money::tryOfTaka($text);

        if ($money === null || $money->isNegative()) {
            $this->error($sheet, $row, $column, 'golive.errors.amount_invalid', ['value' => $text]);

            return Money::zero();
        }

        return $money;
    }

    private function date(string $sheet, int $row, string $column, string $text, bool $required): ?CarbonImmutable
    {
        $ascii = BanglaNumber::toAscii($text);

        if ($ascii === '') {
            if ($required) {
                $this->error($sheet, $row, $column, 'golive.errors.date_format');
            }

            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ascii) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $ascii, YearMonth::TIMEZONE) : null;

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $ascii) {
            $this->error($sheet, $row, $column, 'golive.errors.date_format');

            return null;
        }

        return $date;
    }

    /**
     * Excel drops a leading 0 from numbers typed into General cells (1712345678).
     */
    private function mobile(string $text): string
    {
        $digits = preg_replace('/\D+/', '', BanglaNumber::toAscii($text)) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return MobileNumber::normalize($digits) ?? $text;
    }

    /**
     * @param  array<string, string>  $raw
     */
    private function field(array $raw, string $column): string
    {
        return trim($raw[$column] ?? '');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function error(string $sheet, int $row, ?string $column, string $key, array $replace = []): void
    {
        $this->errors[] = new OpeningImportError($sheet, $row, $column, $key, $replace);
    }
}
