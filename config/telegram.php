<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'admin_roles' => json_decode(env('TELEGRAM_ADMIN_ROLES', '{}'), true, 512, JSON_THROW_ON_ERROR),
    'conversation_ttl_seconds' => 600,
    'callback_ttl_seconds' => 300,
    'terms_version' => 'sandbox-2026-10-19',
];
