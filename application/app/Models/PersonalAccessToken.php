<?php

namespace App\Models;

class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'tenant_id', 'device_id'];
}
