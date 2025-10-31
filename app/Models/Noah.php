<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Noah extends Model
{
     protected $table = 'flood_map_exploded';
     protected $primaryKey = 'gid';
     public $timestamps = false;
}
