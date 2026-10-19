<?php

namespace App\Domain\Models;

final class Posting extends ReadModel
{
    protected $table = 'postings';

    protected function casts(): array
    {
        return ['amount' => 'string'];
    }
}
