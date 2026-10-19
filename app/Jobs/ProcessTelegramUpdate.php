<?php

namespace App\Jobs;

use App\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public readonly int $inboxId)
    {
        $this->beforeCommit();
    }

    public function backoff(): array
    {
        return [2, 10, 30, 60, 120, 300];
    }

    public function handle(Bot $bot): void
    {
        $pending = DB::table('telegram_updates')->where('id', $this->inboxId)->first();
        if (! $pending || $pending->processed_at) {
            return;
        }
        // Account registration finishes before the inbox transaction acquires any financial locks.
        $bot->prepare(json_decode($pending->payload, true, flags: JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($bot): void {
            $inbox = DB::table('telegram_updates')->where('id', $this->inboxId)->lockForUpdate()->first();
            if (! $inbox || $inbox->processed_at) {
                return;
            }
            DB::table('telegram_actors')->where('telegram_id', $inbox->telegram_id)->lockForUpdate()->first();
            $bot->handle(json_decode($inbox->payload, true, flags: JSON_THROW_ON_ERROR), $inbox->id);
            DB::table('telegram_updates')->where('id', $inbox->id)->update(['processed_at' => now()]);
        }, 3);
    }
}
