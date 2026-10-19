<?php

namespace App\Domain\Models;

final class Journal extends ReadModel
{
    protected $table = 'journals';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
