<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Models\SmsMessage;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SMS messages the society sent this member, newest first. Login codes are never shown.
 */
final class NotificationsController
{
    use ResolvesMember;

    public function index(Request $request): JsonResponse
    {
        $page = SmsMessage::query()
            ->where('member_id', self::member($request)->id)
            ->where(fn ($query) => $query->whereNull('template_key')->orWhere('template_key', '!=', SmsTemplateKey::LoginCode->value))
            ->orderByDesc('id')
            ->paginate(ApiResponse::PER_PAGE);

        return ApiResponse::paginated($page, fn (SmsMessage $message): array => [
            'id' => $message->id,
            'kind' => ApiValue::enum($message->template_key === null ? null : SmsTemplateKey::tryFrom($message->template_key)),
            'body' => $message->body,
            'status' => ApiValue::enum($message->status),
            'sent_at' => ApiValue::time($message->sent_at),
        ]);
    }
}
