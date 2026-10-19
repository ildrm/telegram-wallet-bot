<?php

namespace App\Domain\Models;

final class AuditLog extends ReadModel
{
    protected $table = 'audit_logs';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
