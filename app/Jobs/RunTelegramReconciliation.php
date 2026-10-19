<?php

namespace App\Jobs;

use App\Domain\ReconciliationService;
use App\Telegram\AdminAuthorizer;
use App\Telegram\Messages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class RunTelegramReconciliation implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public readonly string $telegramId, public readonly int $inboxId)
    {
        $this->beforeCommit();
    }

    public function handle(ReconciliationService $reconciliation, AdminAuthorizer $auth): void
    {
        if (! $auth->allows($this->telegramId, 'reconcile')) {
            Messages::send($this->telegramId, 'Permission denied.', 'telegram:reconcile:'.$this->inboxId);

            return;
        }
        if (DB::table('outbox')->where('dedupe_key', 'telegram:reconcile:'.$this->inboxId)->exists()) {
            return;
        }
        $result = $reconciliation->run();
        DB::transaction(function () use ($result): void {
            DB::table('audit_logs')->insert(['actor' => $this->telegramId, 'action' => 'reconciliation.requested', 'subject' => 'system', 'metadata' => json_encode(['balanced' => $result['balanced']]), 'created_at' => now()]);
            Messages::send($this->telegramId, 'Reconciliation: '.($result['balanced'] ? 'balanced' : 'DISCREPANCY — investigate; transfers paused')."\nCustody: {$result['custody_balance']} base units\nLedger custody: {$result['ledger_custody']}\nLiabilities: {$result['liabilities']}\nEarned fees: {$result['earned_fees']}", 'telegram:reconcile:'.$this->inboxId);
        }, 3);
    }
}
