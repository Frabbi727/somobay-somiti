<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Notifications\Actions\UpdateSmsTemplate;
use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Gateways\BulkSmsBdGateway;
use App\Domain\Notifications\Jobs\SendSmsJob;
use App\Domain\Notifications\Models\SmsMessage;
use App\Domain\Notifications\Models\SmsTemplate;
use App\Domain\Notifications\Services\SmsSender;
use App\Domain\Settings\Actions\UpdateSomitiProfile;
use App\Domain\Settings\Data\SomitiProfileData;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\SmsTemplates\Pages\EditSmsTemplate;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SmsTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(SmsTemplateSeeder::class);
    app()->setLocale('en');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('welcomes a new member by SMS in Bangla', function (): void {
    $gateway = fakeSms();
    approvedPlan('2026-07', '500');

    $member = onboard(1, '2026-07', ['name_bn' => 'রহিম', 'mobile' => '01712345678']);

    expect($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0][0])->toBe('01712345678')
        ->and($gateway->sent[0][1])->toStartWith('রহিম, Somiti Manager-এ স্বাগতম।')
        ->and($gateway->sent[0][1])->toContain('/portal');

    expect(SmsMessage::query()->sole()->status)->toBe(SmsStatus::Sent)
        ->and(SmsMessage::query()->sole()->member_id)->toBe($member->id);
});

it('names the society from its profile once one is saved', function (): void {
    $gateway = fakeSms();
    approvedPlan('2026-07', '500');
    app(UpdateSomitiProfile::class)(userWithRole(Role::SuperAdmin), new SomitiProfileData(nameBn: 'সবুজ সমবায় সমিতি', nameEn: 'Sabuj Society'));

    onboard(1, '2026-07', ['name_bn' => 'রহিম', 'mobile' => '01712345678']);

    expect($gateway->sent[0][1])->toStartWith('রহিম, সবুজ সমবায় সমিতি-এ স্বাগতম।');
});

it('texts a receipt when a payment is approved', function (): void {
    travelTo('2026-07-05');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $member = onboard(1, '2026-07', ['mobile' => '01733333333']);
    generateMonth('2026-07');

    $gateway = fakeSms();
    travelTo('2026-07-05');
    receivePayment($member, '610');

    $body = collect($gateway->sent)->firstWhere(0, '01733333333')[1] ?? '';

    expect($body)->toContain('৳ ৬১০.০০')
        ->and($body)->toContain('RV-২০২৬-২৭-০০০০০১')
        ->and($body)->toContain('জুলাই ২০২৬');
});

it('sends the dues notice only to members who still owe after advances', function (): void {
    travelTo('2026-07-05');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $owes = onboard(2, '2026-07', ['mobile' => '01711111111']);
    $prepaid = onboard(1, '2026-07', ['mobile' => '01722222222']);
    generateMonth('2026-07');
    travelTo('2026-07-06');
    receivePayment($prepaid, '1120'); // July 610 + 510 advance for August

    $gateway = fakeSms();
    generateMonth('2026-08');
    generateMonth('2026-08');

    $notices = array_values(array_filter($gateway->sent, fn (array $sms): bool => str_contains($sms[1], 'আগস্ট')));

    expect($notices)->toHaveCount(1)
        ->and($notices[0][0])->toBe('01711111111')
        ->and($notices[0][1])->toContain('৳ ১,০২০.০০')
        ->and($notices[0][1])->toContain('১০ আগস্ট ২০২৬');
});

it('retries a failed send and finally marks it failed', function (): void {
    fakeSms(ok: false);
    Queue::fake();

    $message = app(SmsSender::class)->queue('01700000000', 'Test');
    Queue::assertPushed(SendSmsJob::class);

    expect(fn () => (new SendSmsJob($message->id))->handle(app(SmsGateway::class)))->toThrow(RuntimeException::class);

    (new SendSmsJob($message->id))->failed(new RuntimeException('provider down'));

    expect($message->fresh()?->status)->toBe(SmsStatus::Failed)
        ->and($message->fresh()?->attempts)->toBeGreaterThanOrEqual(1);
});

it('never queues the same deduplicated message twice', function (): void {
    fakeSms();
    $sender = app(SmsSender::class);

    $sender->queue('01700000000', 'Once', dedupeKey: 'test:1');
    $sender->queue('01700000000', 'Twice', dedupeKey: 'test:1');

    expect(SmsMessage::query()->count())->toBe(1);
});

it('talks to BulkSMSBD and reports provider errors', function (): void {
    Http::fake([
        'bulksmsbd.test/*' => Http::sequence()
            ->push(['response_code' => 202, 'message_id' => 'abc123'])
            ->push(['response_code' => 1007, 'error_message' => 'Balance insufficient']),
    ]);

    $gateway = new BulkSmsBdGateway(['url' => 'https://bulksmsbd.test/api/smsapi', 'api_key' => 'key', 'sender_id' => '8809617']);

    expect($gateway->send('01712345678', 'হ্যালো')->providerMessageId)->toBe('abc123')
        ->and($gateway->send('01712345678', 'হ্যালো')->error)->toContain('Balance insufficient');

    Http::assertSent(fn ($request): bool => $request['number'] === '8801712345678' && $request['message'] === 'হ্যালো');
});

it('validates template placeholders and lets only the secretary or super admin edit', function (): void {
    $template = SmsTemplate::query()->where('key', SmsTemplateKey::PaymentApproved)->sole();

    expect(fn () => app(UpdateSmsTemplate::class)(userWithRole(Role::Cashier), $template, 'x', 'y', true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSmsTemplate::class)(userWithRole(Role::Secretary), $template, '{nmae} পেয়েছি', 'ok', true))
        ->toThrow(DomainRuleViolation::class, '{nmae}');

    $updated = app(UpdateSmsTemplate::class)(userWithRole(Role::Secretary), $template, '{name}, {amount} পেয়েছি।', '{name}, got {amount}.', true);

    expect($updated->body_bn)->toBe('{name}, {amount} পেয়েছি।');
});

it('edits a template with a before/after confirmation', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Secretary));
    $template = SmsTemplate::query()->where('key', SmsTemplateKey::Welcome)->sole();

    Livewire::test(EditSmsTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body_bn' => 'স্বাগতম {name}!'])
        ->callAction(TestAction::make('save')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    expect($template->fresh()?->body_bn)->toBe('স্বাগতম {name}!');
});
