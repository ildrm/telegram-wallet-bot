<?php

namespace App\Jobs;

use App\Custody\CustodyProvider;
use App\Custody\FakeCustodyProvider;
use App\Domain\DepositService;
use App\Domain\Ledger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessCustodyEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $eventId)
    {
        $this->beforeCommit();
    }

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(CustodyProvider $provider, DepositService $deposits): void
    {
        // No network calls in this transaction: the sole supported adapter is the local simulator.
        DB::transaction(function () use ($provider, $deposits) {
            $row = DB::table('provider_events')->where('id', $this->eventId)->lockForUpdate()->first();
            if (! $row || $row->processed_at) {
                return;
            }
            $event = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($event['asset'] !== config('wallet.asset') || $event['network'] !== config('wallet.network')) {
                Ledger::audit('custody', 'deposit.quarantined', (string) $row->id, ['reason' => 'Unsupported asset or network']);
                DB::table('provider_events')->where('id', $this->eventId)->update(['processed_at' => now(), 'last_error' => 'Quarantined unsupported asset or network.']);

                return;
            }
            if ($provider instanceof FakeCustodyProvider) {
                $provider->observeDeposit($event);
            }
            $deposits->ingest($event);
            DB::table('provider_events')->where('id', $this->eventId)->update(['processed_at' => now(), 'last_error' => null]);
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('provider_events')->where('id', $this->eventId)->update(['last_error' => 'Processing failed; review worker logs.', 'attempts' => $this->tries]);
    }
}
