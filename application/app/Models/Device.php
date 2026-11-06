<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Device extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['pairing_hash', 'credential_hash'];

    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'paired_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'device_branch')->withPivot('tenant_id');
    }
}
