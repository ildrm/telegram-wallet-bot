<?php

namespace App\Jobs;

use App\Custody\CustodyProvider;
use App\Telegram\Messages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class AllocateDepositAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $userId, public readonly string $telegramId, public readonly int $inboxId)
    {
        $this->beforeCommit();
    }

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(CustodyProvider $custody): void
    {
        $user = DB::table('wallet_users')->where('id', $this->userId)->where('telegram_id', $this->telegramId)->firstOrFail();
        $address = DB::table('deposit_addresses')->where('wallet_user_id', $user->id)->value('address');
        // Allocation must be idempotent by wallet code. Never call a real provider while holding a DB lock.
        $address ??= $custody->allocateAddress($user->wallet_code);
        if (! $custody->validateAddress($address)) {
            throw new \RuntimeException('Custody returned an invalid deposit address.');
        }
        DB::transaction(function () use ($address, $user): void {
            DB::table('deposit_addresses')->insertOrIgnore(['wallet_user_id' => $user->id, 'address' => $address, 'created_at' => now()]);
            $stored = DB::table('deposit_addresses')->where('wallet_user_id', $user->id)->value('address');
            if ($stored === null) {
                throw new \RuntimeException('Deposit address allocation conflicted with another wallet.');
            }
            Messages::send($this->telegramId, "SANDBOX · Receive ETH\nNetwork: ethereum-sepolia only\nAddress: {$stored}\nFake custody address: do not send real assets.\nDeposits remain pending until the configured confirmation policy is met. Delays and reorganizations can require review.", 'telegram:receive:'.$this->inboxId, [
                [['text' => 'Copy address', 'copy_text' => ['text' => $stored]]],
                [Messages::button($this->telegramId, 'Back to menu', 'menu')],
            ]);
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        Messages::send($this->telegramId, 'Address allocation is delayed. Open /receive to retry or /support for help. Do not use an address from another person.', 'telegram:receive:failed:'.$this->inboxId);
    }
}
