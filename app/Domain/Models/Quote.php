<?php

namespace App\Domain\Models;

final class Quote extends ReadModel
{
    protected $table = 'quotes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount' => 'string', 'service_fee' => 'string', 'network_fee' => 'string', 'total' => 'string', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
