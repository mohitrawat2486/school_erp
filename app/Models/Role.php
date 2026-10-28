<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name'];
}
