<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Noah extends Model
{
     /**
      * This model lives in the external GIS database (not managed by Laravel
      * migrations). Configure connection via GIS_DB_* env vars in .env.
      */
     protected $connection = 'gis_data';

     protected $table = 'flood_map_exploded';
     protected $primaryKey = 'gid';
     public $timestamps = false;
}
