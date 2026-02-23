<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barangay extends Model
{
    protected $table = 'barangays';
    protected $fillable = ['name', 'city', 'latitude', 'longitude', 'province' ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function weather(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Weather::class);
    }

    public function floodeds(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Flooded::class);
    }

    public function floods(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FloodedGeometry::class);
    }

}
