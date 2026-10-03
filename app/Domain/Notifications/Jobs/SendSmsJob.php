<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Models\SmsMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Sends one logged message; retried with back-off, then marked failed.
 */
final class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $messageId) {}

    public function handle(SmsGateway $gateway): void
    {
        $message = SmsMessage::query()->find($this->messageId);

        if ($message === null || $message->status === SmsStatus::Sent) {
            return;
        }

        $result = $gateway->send($message->to, $message->body);

        $message->forceFill([
            'attempts' => $message->attempts + 1,
            'provider' => $gateway->name(),
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error,
            'status' => $result->ok ? SmsStatus::Sent : SmsStatus::Queued,
            'sent_at' => $result->ok ? CarbonImmutable::now() : null,
        ])->save();

        if (! $result->ok) {
            throw new RuntimeException('SMS '.$message->id.' failed: '.$result->error);
        }
    }

    public function failed(?Throwable $exception): void
    {
        SmsMessage::query()->whereKey($this->messageId)->where('status', SmsStatus::Queued)->update([
            'status' => SmsStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);
    }
}
