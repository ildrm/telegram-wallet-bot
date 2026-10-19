<?php

namespace App\Domain\Models;

final class Deposit extends ReadModel
{
    protected $table = 'deposits';

    protected function casts(): array
    {
        return ['amount' => 'string', 'confirmations' => 'integer'];
    }
}
