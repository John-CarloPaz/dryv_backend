<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Weather extends Model
{
    protected $table = 'weathers';
    protected $fillable = [
        'barangay_id',
        'data',
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
