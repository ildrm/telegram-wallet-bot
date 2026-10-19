<?php

namespace App\Domain\Models;

final class Transfer extends ReadModel
{
    protected $table = 'transfers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount' => 'string', 'service_fee' => 'string', 'network_fee' => 'string', 'actual_network_fee' => 'string', 'total' => 'string'];
    }
}
