<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndpointColumnLayout extends Model
{
    protected $fillable = ['user_id', 'columns', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['columns' => 'array'];
    }
}
