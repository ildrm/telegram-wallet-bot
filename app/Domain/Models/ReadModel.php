<?php

namespace App\Domain\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/** Query projections. Financial writes belong to transactional domain services. */
abstract class ReadModel extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(static fn () => throw new DomainException('Use a transactional domain service.'));
        static::deleting(static fn () => throw new DomainException('Use a transactional domain service.'));
    }
}
