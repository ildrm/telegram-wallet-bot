<?php

namespace App\Domain\Models;

final class WalletUser extends ReadModel
{
    protected $table = 'wallet_users';

    protected function casts(): array
    {
        return ['frozen' => 'boolean', 'accepted_at' => 'immutable_datetime'];
    }
}
