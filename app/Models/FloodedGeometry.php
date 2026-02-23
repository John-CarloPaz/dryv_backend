<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloodedGeometry extends Model
{
    protected $fillable = [
        'barangay_id',
        'flooded_geojson',
    ];

    protected $casts = [
        'flooded_polygons' => 'array'
    ];

    public function barangay(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }
}
