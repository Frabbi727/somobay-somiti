<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Models\SmsMessage;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

/*
| The app's read-only lists: dues (open by default, filterable), dividends and the member's SMS
| history (login codes never shown). Every list is the signed-in member's own rows only.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-05 10:00'));
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->other = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-08'));
    $this->token = memberToken($this->member);
});

function smsFor(Member $member, ?string $template, string $body): SmsMessage
{
    return SmsMessage::query()->create([
        'to' => $member->mobile, 'member_id' => $member->id, 'template_key' => $template, 'body' => $body,
        'segments' => 1, 'status' => SmsStatus::Sent, 'attempts' => 1, 'sent_at' => now(),
    ]);
}

it('lists only this member\'s open dues by default, newest month first, paginated', function (): void {
    $response = $this->withToken($this->token)->getJson('/api/v1/dues')->assertOk();
    $rows = collect($response->json('data'));

    expect($rows->pluck('month')->unique()->values()->all())->toBe(['2026-08', '2026-07'])
        ->and($rows->pluck('status.value')->unique()->values()->all())->toBe(['open'])
        ->and($response->json('meta'))->toMatchArray(['current_page' => 1, 'per_page' => 20])
        ->and($response->json('data.0'))->toHaveKeys(['id', 'month', 'type', 'amount', 'paid', 'outstanding', 'due_date', 'status'])
        ->and($rows->pluck('id')->intersect(Due::query()->where('member_id', $this->other->id)->pluck('id'))->all())->toBe([]);
});

it('filters dues by type and by any status, and refuses an unknown status', function (): void {
    $types = collect($this->withToken($this->token)->getJson('/api/v1/dues?type=registration&status=all')->assertOk()->json('data'))->pluck('type.value');

    expect($types->unique()->values()->all())->toBe(['registration']);
    $this->withToken($this->token)->getJson('/api/v1/dues?status=bogus')->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);
});

it('lists the member\'s SMS history without login codes or other members\' messages', function (): void {
    smsFor($this->member, 'login_code', 'code 123456');
    smsFor($this->member, 'dues_generated', 'Your dues');
    smsFor($this->other, 'dues_generated', 'Other member');

    $rows = collect($this->withToken($this->token)->getJson('/api/v1/notifications')->assertOk()->json('data'));

    expect($rows->pluck('body')->all())->toContain('Your dues')->not->toContain('code 123456')->not->toContain('Other member')
        ->and($rows->firstWhere('body', 'Your dues'))->toHaveKeys(['id', 'kind', 'body', 'status', 'sent_at']);
});

it('lists dividends (none yet)', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/dividends')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});
