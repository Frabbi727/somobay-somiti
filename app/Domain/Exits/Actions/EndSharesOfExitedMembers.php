<?php

declare(strict_types=1);

namespace App\Domain\Exits\Actions;

use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * One-off repair for exits approved before approval ended the member's shares (fixed 2026-10-11):
 * ends their share lots from the month after the exit month. Nothing is posted.
 *
 * Dividend lines already posted to such members are only reported — whether to pay or keep them
 * is the committee's decision, and a posted year-end is never changed.
 *
 * @phpstan-type Report array{
 *     fixed: list<array{member_no: string, shares: int}>,
 *     skipped: list<array{member_no: string, reason: string}>,
 *     dividends: list<array{member_no: string, year_end_id: int, amount_poisha: int, status: string}>
 * }
 */
final class EndSharesOfExitedMembers
{
    public function __construct(
        private readonly ShareChanger $shares,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @return Report
     */
    public function __invoke(User $actor, bool $apply): array
    {
        $report = ['fixed' => [], 'skipped' => [], 'dividends' => $this->dividendsAfterExit()];

        $exits = MemberExit::query()
            ->whereIn('status', [ExitStatus::Approved, ExitStatus::Paid])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('share_lots')
                ->whereColumn('share_lots.member_id', 'member_exits.member_id')
                ->whereRaw("(share_lots.ended_from IS NULL OR share_lots.ended_from > member_exits.exit_month + INTERVAL '1 month')"))
            ->orderBy('id')
            ->get();

        foreach ($exits as $exit) {
            // One transaction per member; a dry run rolls each back after seeing what would change.
            $this->causer->withCauser($actor, function () use ($actor, $exit, $apply, &$report): void {
                DB::beginTransaction();

                try {
                    $member = Member::query()->whereKey($exit->member_id)->lockForUpdate()->firstOrFail();
                    $ended = $this->shares->endAllForExit($actor, $member, $exit->exit_month, $exit->reason);
                    $report['fixed'][] = ['member_no' => (string) $member->member_no, 'shares' => $ended];
                    $apply ? DB::commit() : DB::rollBack();
                } catch (DomainRuleViolation $violation) {
                    DB::rollBack();
                    $report['skipped'][] = ['member_no' => (string) $exit->member->member_no, 'reason' => $violation->getMessage()];
                } catch (\Throwable $exception) {
                    DB::rollBack();

                    throw $exception;
                }
            });
        }

        return $report;
    }

    /**
     * Dividend lines from year-ends posted after the member's exit was approved.
     *
     * @return list<array{member_no: string, year_end_id: int, amount_poisha: int, status: string}>
     */
    private function dividendsAfterExit(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT m.member_no, d.year_end_id, d.amount_poisha, d.status
            FROM dividend_lines d
            JOIN year_ends y ON y.id = d.year_end_id
            JOIN member_exits x ON x.member_id = d.member_id AND x.status IN ('approved', 'paid')
            JOIN members m ON m.id = d.member_id
            WHERE y.posted_at > x.approved_at
            ORDER BY m.member_no, d.year_end_id
            SQL);

        return array_values(array_map(fn (\stdClass $row): array => [
            'member_no' => (string) $row->member_no,
            'year_end_id' => (int) $row->year_end_id,
            'amount_poisha' => (int) $row->amount_poisha,
            'status' => (string) $row->status,
        ], $rows));
    }
}
