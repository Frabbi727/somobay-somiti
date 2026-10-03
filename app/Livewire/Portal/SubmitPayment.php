<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A member reports a bKash/Nagad payment (TrxID + screenshot); it waits for a checker's approval.
 */
final class SubmitPayment extends PortalComponent
{
    use WithFileUploads;

    public string $method = 'bkash';

    public string $amount = '';

    public string $trxId = '';

    public string $receivedOn = '';

    public ?TemporaryUploadedFile $proof = null;

    public string $idempotencyKey = '';

    public ?string $done = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->receivedOn = CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString();
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function submit(): void
    {
        $this->error = null;

        $this->validate([
            'method' => ['required', 'in:bkash,nagad'],
            'amount' => ['required', fn (string $attribute, mixed $value, \Closure $fail) => Money::tryOfTaka((string) $value)?->isPositive() === true ? null : $fail(__('money.validation.invalid'))],
            'trxId' => ['required', 'regex:/^[A-Za-z0-9]{6,40}$/'],
            'receivedOn' => ['required', 'date', 'before_or_equal:today'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('somiti.max_proof_kb')],
        ]);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $payment = app(RecordPayment::class)($user, PaymentData::fromForm([
                'member_id' => $this->member()->id,
                'method' => $this->method,
                'amount' => Money::ofTaka($this->amount),
                'trx_id' => $this->trxId,
                'received_on' => $this->receivedOn,
                'proof_path' => $this->proof?->store('payment-proofs', 'local'),
                'idempotency_key' => $this->idempotencyKey,
            ]));
        } catch (DomainRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        $this->done = __('portal.submit.done', ['amount' => $payment->amount_poisha->format(app()->getLocale())]);
        $this->reset(['amount', 'trxId', 'proof']);
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function render(): View
    {
        return view('livewire.portal.submit-payment', [
            'methods' => [PaymentMethod::Bkash, PaymentMethod::Nagad],
        ])->title(__('portal.nav.submit'));
    }
}
