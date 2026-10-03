<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Gateways;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Data\SmsResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * BulkSMSBD (bulksmsbd.net) HTTP API. Configure SMS_DRIVER=bulksmsbd and BULKSMSBD_* in .env.
 * Success is response_code 202; anything else is reported as a failure and retried.
 */
final class BulkSmsBdGateway implements SmsGateway
{
    /**
     * @param  array{url: string, api_key: string, sender_id: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'bulksmsbd';
    }

    public function send(string $to, string $body): SmsResult
    {
        try {
            $response = Http::asForm()->timeout(15)->post($this->config['url'], [
                'api_key' => $this->config['api_key'],
                'senderid' => $this->config['sender_id'],
                'number' => '88'.$to,
                'message' => $body,
                'type' => 'text',
            ]);
        } catch (ConnectionException $exception) {
            return SmsResult::failed($exception->getMessage());
        }

        $code = (int) $response->json('response_code', 0);

        return $response->successful() && $code === 202
            ? SmsResult::sent((string) $response->json('message_id', ''))
            : SmsResult::failed(sprintf('HTTP %d, code %d: %s', $response->status(), $code, (string) $response->json('error_message', $response->body())));
    }
}
