<?php

return [
    'live_enabled' => (bool) env('WALLET_LIVE_ENABLED', false),
    'custody_driver' => env('CUSTODY_DRIVER', 'fake'),
    'asset' => 'ETH',
    'network' => 'ethereum-sepolia',
    'confirmations' => 12,
    'quote_ttl_seconds' => 120,
    'terms_version' => 'sandbox-2026-10-19',
    'custody_webhook_secret' => env('CUSTODY_WEBHOOK_SECRET', ''),
    'fake' => [
        'network_fee' => '21000000000000',
        'mode' => env('FAKE_CUSTODY_MODE', 'submitted'),
    ],
    'fees' => [
        'version' => 'sandbox-v1',
        'internal' => ['fixed' => '10000000000000', 'bps' => 0, 'min' => '0', 'max' => null],
        'withdrawal' => ['fixed' => '100000000000000', 'bps' => 10, 'min' => '0', 'max' => null],
    ],
    'limits' => ['transaction_max' => '1000000000000000000', 'daily_max' => '5000000000000000000'],
    // Immutable configuration for sandbox approvals; no destination change endpoint.
    'owner_destination' => env('OWNER_PAYOUT_ADDRESS', ''),
    'settlement_delay_hours' => 24,
];
