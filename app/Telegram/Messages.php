<?php

namespace App\Telegram;

use Illuminate\Support\Facades\DB;

final class Messages
{
    public static function button(string $telegramId, string $label, string $action, array $context = [], int $seconds = 600): array
    {
        $token = bin2hex(random_bytes(20));
        DB::table('telegram_callbacks')->insert([
            'token' => hash('sha256', $token), 'telegram_id' => $telegramId, 'action' => $action,
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'expires_at' => now()->addSeconds($seconds),
        ]);

        return ['text' => $label, 'callback_data' => $token];
    }

    public static function send(string $telegramId, string $text, string $dedupeKey, array $rows = []): void
    {
        $payload = ['chat_id' => $telegramId, 'text' => $text];
        if ($rows !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $rows];
        }
        DB::table('outbox')->insertOrIgnore([
            'type' => 'telegram.notify', 'dedupe_key' => $dedupeKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
