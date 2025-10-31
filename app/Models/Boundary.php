<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Boundary extends Model
{
    protected $table = 'pampanga_boundary';
    protected $primaryKey = 'gid';
    public $timestamps = false;
}
