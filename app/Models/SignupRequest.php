<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public "contact admin for an account" request from the login page. Platform
 * level (not tenant-scoped) — a prospect isn't in any organization yet.
 */
class SignupRequest extends Model
{
    public const TYPES = ['organization', 'mssp', 'individual', 'other'];

    protected $fillable = [
        'name', 'email', 'account_type', 'organization', 'message', 'status', 'ip_address',
    ];
}
