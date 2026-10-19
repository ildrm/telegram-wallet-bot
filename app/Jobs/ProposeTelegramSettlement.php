<?php

namespace App\Jobs;

use App\Finance\FeeSettlementService;
use App\Telegram\AdminAuthorizer;
use App\Telegram\Amount;
use App\Telegram\Messages;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ProposeTelegramSettlement implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $telegramId, public readonly string $amount, public readonly int $inboxId)
    {
        $this->beforeCommit();
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(FeeSettlementService $settlements, AdminAuthorizer $auth): void
    {
        $dedupeKey = 'telegram:settlement:'.$this->inboxId;
        if (DB::table('outbox')->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }
        if (! $auth->allows($this->telegramId, 'settlement')) {
            Messages::send($this->telegramId, 'Permission denied.', $dedupeKey);

            return;
        }
        try {
            $proposal = $settlements->propose($this->telegramId, $this->amount, $dedupeKey);
        } catch (DomainException|\InvalidArgumentException $e) {
            Messages::send($this->telegramId, $e->getMessage(), $dedupeKey);

            return;
        }
        DB::transaction(function () use ($proposal, $dedupeKey): void {
            Messages::send($this->telegramId, "SANDBOX · Fee settlement proposed\nID: {$proposal['id']}\nDestination: {$proposal['destination']}\nOwner receives: ".Amount::format((string) $proposal['amount'])."\nMaximum network cost: ".Amount::format((string) $proposal['network_fee'])."\nTotal earned-fee deduction: ".Amount::format((string) $proposal['total'])."\nA different authorized Owner must open /settlements and approve. Execution waits at least 24 hours and rechecks backing, reconciliation, roles and destination.", $dedupeKey, [
                [Messages::button($this->telegramId, 'Settlement list', 'admin_settlement_list')],
            ]);
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        Messages::send($this->telegramId, 'Settlement proposal is delayed. Check /settlements before retrying.', 'telegram:settlement:failed:'.$this->inboxId);
    }
}
