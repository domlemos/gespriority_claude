<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['action', 'ip_address', 'user_agent'])]
class TokenAuditLog extends Model
{
    const UPDATED_AT = null;

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
