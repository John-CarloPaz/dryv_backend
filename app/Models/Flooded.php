<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flooded extends Model
{
    protected $table = 'floodeds';
    protected $fillable = [
        'barangay_id',
        'reported_at',
        'risk_level',
        'accumulated_rainfall',
        'flooded_polygon',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'accumulated_rainfall' => 'float',
    ];

    public function barangay(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }
}
