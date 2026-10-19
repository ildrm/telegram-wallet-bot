<?php

namespace App\Telegram;

final class AdminAuthorizer
{
    private const PERMISSIONS = [
        'status' => ['Owner', 'Operations', 'Compliance', 'Finance', 'Support'],
        'review' => ['Owner', 'Operations', 'Compliance', 'Finance'],
        'alerts' => ['Owner', 'Operations', 'Compliance', 'Finance'],
        'accounts' => ['Owner', 'Operations', 'Compliance'],
        'freeze' => ['Owner', 'Compliance'],
        'hold' => ['Owner', 'Compliance'],
        'pause' => ['Owner', 'Operations'],
        'reconcile' => ['Owner', 'Finance'],
        'revenue' => ['Owner', 'Finance'],
        'support' => ['Owner', 'Support'],
        'settlement' => ['Owner', 'Finance'],
        'settlement_approve' => ['Owner'],
    ];

    public function allows(string $telegramId, string $action): bool
    {
        $roles = config('telegram.admin_roles', [])[$telegramId] ?? [];

        return count(array_intersect((array) $roles, self::PERMISSIONS[$action] ?? [])) > 0;
    }
}
