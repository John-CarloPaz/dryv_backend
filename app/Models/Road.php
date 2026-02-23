<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Road extends Model
{
    protected $connection = 'gis_data';
    protected $table = 'roads';
}
