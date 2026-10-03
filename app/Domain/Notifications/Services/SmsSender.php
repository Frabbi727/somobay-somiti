<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Jobs\SendSmsJob;
use App\Domain\Notifications\Models\SmsMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Logs a message and queues it. A dedupe key makes a message send at most once, so re-running a
 * generator or retrying an approval never texts the member twice. Jobs dispatch after commit.
 */
final class SmsSender
{
    public function __construct(private readonly SmsComposer $composer) {}

    /**
     * @param  array<string, string>  $values
     */
    public function template(SmsTemplateKey $key, string $to, array $values, ?Member $member = null, ?string $dedupeKey = null, ?Model $related = null): ?SmsMessage
    {
        $body = $this->composer->compose($key, $values);

        return $body === null ? null : $this->queue($to, $body, $member, $key->value, $dedupeKey, $related);
    }

    public function queue(string $to, string $body, ?Member $member = null, ?string $templateKey = null, ?string $dedupeKey = null, ?Model $related = null): ?SmsMessage
    {
        if ($dedupeKey !== null && SmsMessage::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            $message = SmsMessage::query()->create([
                'to' => $to,
                'member_id' => $member?->id,
                'template_key' => $templateKey,
                'dedupe_key' => $dedupeKey,
                'body' => $body,
                'segments' => SmsSegments::count($body),
                'status' => SmsStatus::Queued,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        SendSmsJob::dispatch($message->id)->afterCommit();

        return $message;
    }
}
