<?php

namespace App\Jobs;

use App\Domain\WithdrawalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DeliverOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public int $outboxId) {}

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(WithdrawalService $withdrawals): void
    {
        $row = DB::transaction(function () {
            $row = DB::table('outbox')->where('id', $this->outboxId)->lockForUpdate()->first();
            if (! $row || $row->delivered_at || now()->lt($row->available_at)) {
                return null;
            }
            DB::table('outbox')->where('id', $row->id)->update(['attempts' => $row->attempts + 1, 'available_at' => now()->addSeconds(120)]);

            return $row;
        }, 3);
        if (! $row) {
            return;
        }
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        try {
            if ($row->type === 'withdrawal.process') {
                $withdrawals->process($payload['transaction_id']);
            } elseif ($row->type === 'telegram.notify') {
                $this->notify($payload);
            } else {
                throw new \RuntimeException('Unknown outbox topic.');
            }
            DB::table('outbox')->where('id', $row->id)->update(['delivered_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('outbox')->where('id', $row->id)->update(['available_at' => now()->addSeconds(min(3600, 30 * (2 ** min($row->attempts, 6))))]);
            // Do not chain HTTP exceptions which could expose the Telegram bot token URL.
            throw new \RuntimeException('Outbox delivery failed; durable record retained for retry.');
        }
    }

    private function notify(array $payload): void
    {
        $token = (string) config('telegram.bot_token');
        if ($token === '') {
            throw new \RuntimeException('Telegram bot token not configured.');
        }
        $method = $payload['method'] ?? 'sendMessage';
        if (! in_array($method, ['sendMessage', 'answerCallbackQuery'], true)) {
            throw new \RuntimeException('Invalid Telegram method.');
        }
        unset($payload['method']);
        if (isset($payload['user_id'])) {
            $payload['chat_id'] = DB::table('wallet_users')->where('id', $payload['user_id'])->value('telegram_id');
            unset($payload['user_id']);
        }
        if ($method === 'sendMessage' && empty($payload['chat_id'])) {
            throw new \RuntimeException('Missing private recipient.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(15)->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
            // Telegram callback queries expire quickly. An expired acknowledgment is not a failed payment.
            if ($method === 'answerCallbackQuery' && $response->status() === 400) {
                return;
            }
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new \RuntimeException('Telegram rejected delivery.');
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Telegram transport failed.');
        }
    }
}
