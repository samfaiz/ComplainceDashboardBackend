<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EndpointColumnLayout extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'user_id', 'columns', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
