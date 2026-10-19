<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class TelegramWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        $update = $request->json()->all();
        $message = $update['callback_query']['message'] ?? $update['message'] ?? null;
        $actor = $update['callback_query']['from'] ?? $message['from'] ?? null;
        $actorId = is_array($actor) ? ($actor['id'] ?? null) : null;
        $id = is_int($actorId) || is_string($actorId) ? (string) $actorId : '';
        // Authenticated Telegram updates still must be from a private human actor.
        if (! is_array($message) || ($message['chat']['type'] ?? '') !== 'private'
            || ! preg_match('/^[1-9][0-9]{0,19}$/D', $id)
            || (string) ($message['chat']['id'] ?? '') !== $id
            || ($actor['is_bot'] ?? true) !== false
            || (isset($update['callback_query']) && (! is_string($update['callback_query']['id'] ?? null) || strlen($update['callback_query']['id']) > 128))
            || (isset($update['callback_query']) && (! is_string($update['callback_query']['data'] ?? null) || strlen($update['callback_query']['data']) > 64))
            || (isset($message['text']) && ! is_string($message['text']))
            || ! preg_match('/^[0-9]{1,20}$/D', (string) ($update['update_id'] ?? ''))) {
            return response()->json(['ok' => true]);
        }
        $payloadHash = hash('sha256', CanonicalJson::encode($update));
        DB::transaction(function () use ($update, $id, $payloadHash): void {
            DB::table('telegram_updates')->insertOrIgnore([
                'update_id' => (string) $update['update_id'], 'telegram_id' => $id,
                'payload' => json_encode($update, JSON_THROW_ON_ERROR), 'created_at' => now(),
                'payload_hash' => $payloadHash,
            ]);
            $row = DB::table('telegram_updates')->where('update_id', (string) $update['update_id'])->first();
            abort_if($row && ! hash_equals($row->payload_hash ?? '', $payloadHash), 409, 'Conflicting Telegram update identifier.');
            if ($row && ! $row->processed_at) {
                // The database queue and inbox share the same transaction/connection.
                Queue::connection('database')->push(new ProcessTelegramUpdate($row->id));
            }
        }, 3);

        return response()->json(['ok' => true]);
    }
}
