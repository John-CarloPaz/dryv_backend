<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Weather extends Model
{
    protected $table = 'weathers';
    protected $fillable = [
        'barangay_id',
        'data',
        'si_score',
        'accumulated_rainfall',
        'ave_pop_percentage',
        'solar_irradiance',
        'temp_min',
        'temp_max',
        'temp_avg',
        'hargreaves_index',
        'runoff',
        'hargreaves_hourly',
        'ave_pop_percentage',
        'soil_moisture',
        'fetched_at',
    ];

    protected $casts = [
        'data' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function barangay(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }
}
