<?php

namespace App\Jobs;

use App\Domain\WalletService;
use App\Telegram\Amount;
use App\Telegram\Messages;
use Carbon\Carbon;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class PrepareTelegramQuote implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $userId, public readonly string $telegramId, public readonly array $context, public readonly string $amount, public readonly int $inboxId)
    {
        $this->beforeCommit();
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(WalletService $wallet): void
    {
        if (DB::table('telegram_quote_requests')->where('inbox_id', $this->inboxId)->value('status') !== 'queued') {
            return;
        }
        if (DB::table('outbox')->where('dedupe_key', 'telegram:quote:'.$this->inboxId)->exists()) {
            return;
        }
        try {
            $quote = $wallet->quote($this->userId, $this->context['kind'], $this->amount, $this->context['recipient'] ?? null, $this->context['destination'] ?? null);
        } catch (DomainException|\InvalidArgumentException $e) {
            Messages::send($this->telegramId, $e->getMessage().' Open /menu to start again.', 'telegram:quote:'.$this->inboxId);

            return;
        }
        DB::transaction(function () use ($quote): void {
            $request = DB::table('telegram_quote_requests')->where('inbox_id', $this->inboxId)->lockForUpdate()->first();
            if (! $request || $request->status !== 'queued') {
                return;
            }
            DB::table('telegram_quote_requests')->where('inbox_id', $this->inboxId)->update(['status' => 'quoted']);
            $seconds = max(1, (int) now()->diffInSeconds(Carbon::parse($quote['expires_at']), false));
            $to = $this->context['recipient'] ?? $this->context['destination'];
            $text = "SANDBOX · Review transfer\nNetwork: ethereum-sepolia\nRecipient: {$to}\nRecipient gets: ".Amount::format($quote['amount'])."\nService fee: ".Amount::format($quote['service_fee'])."\nMaximum network cost: ".Amount::format($quote['network_fee'])."\nTotal deduction: ".Amount::format($quote['total'])."\nExpires: {$quote['expires_at']} UTC\nOnly Confirm authorizes this deduction.";
            Messages::send($this->telegramId, $text, 'telegram:quote:'.$this->inboxId, [
                [Messages::button($this->telegramId, 'Confirm transfer', 'confirm', ['quote_id' => $quote['id']], $seconds)],
                [Messages::button($this->telegramId, 'Cancel', 'cancel')],
            ]);
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        Messages::send($this->telegramId, 'The custody quote is unavailable. No transfer was authorized. Open /menu to try again.', 'telegram:quote:failed:'.$this->inboxId);
    }
}
