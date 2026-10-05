<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
use App\Filament\Support\Display;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Requests\Api\SubmitPaymentRequest;
use App\Http\Resources\Api\PaymentSummaryResource;
use App\Models\User;
use App\Reports\ReceiptDocument;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The portal's Payments page and Pay Online form. Payment ids are only ever looked up within the
 * signed-in member's own payments.
 */
final class PaymentsController
{
    use ResolvesMember;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(PaymentStatus::class)]]);
        $status = $filters['status'] ?? null;

        $page = Payment::query()
            ->where('member_id', self::member($request)->id)
            ->when(is_string($status), fn ($query) => $query->where('status', $status))
            ->orderByDesc('received_on')->orderByDesc('id')
            ->paginate(ApiResponse::PER_PAGE);

        return ApiResponse::paginated($page, fn (Payment $payment): array => PaymentSummaryResource::make($payment));
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        return ApiResponse::ok($this->detail($this->own($request, $payment)));
    }

    public function receipt(Request $request, int $payment): JsonResponse
    {
        $record = $this->own($request, $payment);
        abort_unless($record->status === PaymentStatus::Approved, 404);

        return ApiResponse::ok(['url' => ReceiptDocument::signedUrl($record)]);
    }

    /**
     * Pending until staff approve. A retry with the same idempotency key returns the same payment
     * (and keeps its proof); a different amount under the same key is a 409.
     */
    public function store(SubmitPaymentRequest $request, RecordPayment $record): JsonResponse
    {
        $member = self::member($request);
        $key = (string) $request->string('idempotency_key');
        $existing = Payment::query()->where('idempotency_key', $key)->first();
        $path = $existing === null ? $request->file('proof')?->store('payment-proofs', 'local') : $existing->proof_path;

        try {
            /** @var User $actor */
            $actor = $request->user();
            $payment = $record($actor, PaymentData::fromForm([
                'member_id' => $member->id,
                'method' => (string) $request->string('method'),
                'amount' => (string) $request->string('amount'),
                'trx_id' => strtoupper((string) $request->string('trx_id')),
                'received_on' => (string) $request->string('received_on'),
                'idempotency_key' => $key,
                'proof_path' => is_string($path) ? $path : null,
            ]));
        } catch (Throwable $e) {
            if ($existing === null && is_string($path)) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        return ApiResponse::ok($this->detail($payment), __('portal.submit.done', ['amount' => Display::money($payment->amount_poisha)]), 201);
    }

    private function own(Request $request, int $id): Payment
    {
        return Payment::query()->where('member_id', self::member($request)->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Payment $payment): array
    {
        $allocations = PaymentAllocation::query()->where('payment_id', $payment->id)->with('due')->orderBy('id')->get();
        $toAdvance = Money::ofPoisha((int) AdvanceLedgerEntry::query()
            ->where('payment_id', $payment->id)
            ->where('kind', AdvanceEntryKind::PaymentSurplus)
            ->sum('delta_poisha'));

        return [
            ...PaymentSummaryResource::make($payment),
            'rejection_reason' => $payment->rejection_reason,
            'approved_at' => ApiValue::time($payment->approved_at),
            'receipt_available' => $payment->status === PaymentStatus::Approved,
            'allocations' => $allocations->map(fn (PaymentAllocation $allocation): array => [
                'due_id' => $allocation->due_id,
                'month' => ApiValue::month($allocation->due->month),
                'type' => ApiValue::enum($allocation->due->type),
                'amount' => ApiValue::money($allocation->amount_poisha),
            ])->all(),
            'to_advance' => ApiValue::money($toAdvance),
        ];
    }
}
