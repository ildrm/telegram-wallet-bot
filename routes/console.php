<?php

use App\Domain\ReconciliationService;
use App\Domain\WithdrawalService;
use App\Finance\FeeSettlementService;
use App\Jobs\DeliverOutbox;
use App\Jobs\ProcessCustodyEvent;
use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('wallet:drain', function () {
    $count = 0;
    foreach (DB::table('outbox')->whereNull('delivered_at')->where('available_at', '<=', now())->orderBy('id')->limit(100)->get() as $row) {
        DeliverOutbox::dispatch($row->id)->onConnection('database');
        $count++;
    }
    foreach (DB::table('telegram_updates')->whereNull('processed_at')->orderBy('id')->limit(100)->get() as $row) {
        ProcessTelegramUpdate::dispatch($row->id)->onConnection('database');
    }
    foreach (DB::table('provider_events')->whereNull('processed_at')->whereNull('last_error')->orderBy('id')->limit(100)->get() as $row) {
        ProcessCustodyEvent::dispatch($row->id)->onConnection('database');
    }
    $this->info("Queued $count outbox records and pending inbox recovery.");
})->purpose('Dispatch durable outbox and recover unfinished webhook inboxes');

Artisan::command('wallet:poll', function (WithdrawalService $withdrawals, FeeSettlementService $settlements) {
    $errors = 0;
    foreach (DB::table('transfers')->where('kind', 'withdrawal')->whereIn('status', ['reserved', 'submitting', 'unknown', 'broadcast'])->orderBy('updated_at')->limit(100)->get() as $row) {
        try {
            $withdrawals->process($row->id);
        } catch (Throwable) {
            $errors++;
        }
    }
    foreach (DB::table('fee_settlements')->whereIn('status', ['approved', 'submitting', 'unknown', 'broadcast'])->orderBy('updated_at')->limit(100)->get() as $row) {
        try {
            $settlements->process($row->id);
        } catch (Throwable) {
            $errors++;
        }
    }
    $this->info("Polling complete; $errors items require retry/review.");

    return $errors ? 1 : 0;
})->purpose('Reconcile original withdrawal identifiers; submit only previously reserved requests');

Artisan::command('wallet:reconcile', function (ReconciliationService $service) {
    $result = $service->run();
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['balanced'] ? 0 : 1;
})->purpose('Compare independent custody assets, journal integrity and customer backing');

Artisan::command('wallet:status', function () {
    $this->line(json_encode([
        'mode' => 'FAKE CUSTODY ONLY', 'controls' => DB::table('controls')->pluck('value', 'key')->all(),
        'outbox_pending' => DB::table('outbox')->whereNull('delivered_at')->count(),
        'uncertain_withdrawals' => DB::table('transfers')->whereIn('status', ['submitting', 'unknown', 'manual_review'])->count(),
        'unprocessed_custody_events' => DB::table('provider_events')->whereNull('processed_at')->count(),
        'last_reconciliation' => DB::table('reconciliations')->orderByDesc('id')->first(),
    ], JSON_THROW_ON_ERROR));
})->purpose('Infrastructure operational status without an HTTP dashboard');

Schedule::command('wallet:drain')->everyMinute()->withoutOverlapping(5);
Schedule::command('wallet:poll')->everyMinute()->withoutOverlapping(5);
Schedule::command('wallet:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
