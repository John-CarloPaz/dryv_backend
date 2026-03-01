<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class CrucialFacility extends Model
{
    protected $table = 'crucial_facilities';

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'barangay',
        'municipality',
        'postal_code',
        'country',
        'type',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public const TYPE_POLICE = 'police';
    public const TYPE_HOSPITAL = 'hospital';
    public const TYPE_EVACUATION_CENTER = 'evacuation center';

    /**
     * Canonical facility types as stored by the seeder.
     *
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_POLICE,
            self::TYPE_HOSPITAL,
            self::TYPE_EVACUATION_CENTER,
        ];
    }

    public function scopeHasCoordinates(Builder $query): Builder
    {
        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
    }
}
