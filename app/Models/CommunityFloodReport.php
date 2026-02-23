<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityFloodReport extends Model
{
    protected $table = 'community_flood_reports';

    protected $fillable = [
        'user_id',
        'road_gid',
        'report_lat',
        'report_lng',
        'snapped_lat',
        'snapped_lng',
        'meters_away',
        'road_line_fraction',
        'segment_key',
        'barangay_id',
        'weather_id',
        'hazard_weight',
        'rainfall',
        'estimated_depth',
        'chi_score',
        'risk_level',
    ];

    protected $casts = [
        'report_lat' => 'float',
        'report_lng' => 'float',
        'snapped_lat' => 'float',
        'snapped_lng' => 'float',
        'meters_away' => 'float',
        'road_line_fraction' => 'float',
        'segment_key' => 'int',
        'hazard_weight' => 'float',
        'rainfall' => 'float',
        'estimated_depth' => 'int',
        'chi_score' => 'float',
        'risk_level' => 'int',
        'barangay_id' => 'int',
        'weather_id' => 'int',
        'road_gid' => 'int',
        'user_id' => 'int',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function weather(): BelongsTo
    {
        return $this->belongsTo(Weather::class);
    }
}
