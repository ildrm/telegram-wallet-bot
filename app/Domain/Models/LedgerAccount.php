<?php

namespace App\Domain\Models;

final class LedgerAccount extends ReadModel
{
    protected $table = 'ledger_accounts';

    protected function casts(): array
    {
        return [];
    }
}
