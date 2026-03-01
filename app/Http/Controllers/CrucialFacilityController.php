<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrucialFacilityNearestRequest;
use App\Models\CrucialFacility;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CrucialFacilityController extends Controller
{
    /**
     * GET /api/crucial-facilities/nearest
     * Query params: latitude, longitude, [type], [limit_per_type]
     */
    public function nearest(CrucialFacilityNearestRequest $request): JsonResponse
    {
        $data = $request->validated();

        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $limitPerType = array_key_exists('limit_per_type', $data) ? (int) $data['limit_per_type'] : 10;

        $types = array_key_exists('type', $data)
            ? [(string) $data['type']]
            : CrucialFacility::allowedTypes();

        $out = [];
        foreach ($types as $type) {
            $out[$this->responseTypeKey($type)] = $this->nearestForType($type, $lat, $lng, $limitPerType);
        }

        return response()->json([
            'status' => 'ok',
            'query' => [
                'latitude' => $lat,
                'longitude' => $lng,
                'type' => array_key_exists('type', $data) ? (string) $data['type'] : null,
                'limit_per_type' => $limitPerType,
            ],
            'data' => $out,
        ]);
    }

    private function responseTypeKey(string $dbType): string
    {
        $t = strtolower(trim($dbType));
        return $t === 'evacuation center' ? 'evacuation_center' : $t;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nearestForType(string $type, float $lat, float $lng, int $limit): array
    {
        // Preferred path: PostGIS distance on-the-fly from lat/lng columns.
        try {
            $ptSql = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';
            $facilityGeogSql = 'ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography';

            $rows = CrucialFacility::query()
                ->hasCoordinates()
                ->where('type', $type)
                ->select([
                    'id',
                    'name',
                    'latitude',
                    'longitude',
                    'barangay',
                    'municipality',
                    'postal_code',
                    'country',
                    'type',
                ])
                ->selectRaw("ST_Distance({$facilityGeogSql}, {$ptSql}) AS distance_m", [$lng, $lat])
                ->orderBy('distance_m')
                ->limit($limit)
                ->get();

            return $rows->map(function (CrucialFacility $f) {
                $distance = $f->getAttribute('distance_m');
                $distance = is_numeric($distance) ? round((float) $distance, 2) : null;

                return [
                    'id' => (int) $f->id,
                    'name' => (string) $f->name,
                    'latitude' => $f->latitude,
                    'longitude' => $f->longitude,
                    'barangay' => $f->barangay,
                    'municipality' => $f->municipality,
                    'postal_code' => $f->postal_code,
                    'country' => $f->country,
                    'type' => (string) $f->type,
                    'type_key' => $this->responseTypeKey((string) $f->type),
                    'distance_m' => $distance,
                ];
            })->all();
        } catch (\Throwable $e) {
            // Fallback: PHP haversine, helpful in non-PostGIS environments.
            Log::warning('CrucialFacilityController: PostGIS distance query failed, using PHP fallback', [
                'error' => $e->getMessage(),
            ]);

            $rows = CrucialFacility::query()
                ->hasCoordinates()
                ->where('type', $type)
                ->limit(5000)
                ->get([
                    'id',
                    'name',
                    'latitude',
                    'longitude',
                    'barangay',
                    'municipality',
                    'postal_code',
                    'country',
                    'type',
                ]);

            $scored = [];
            foreach ($rows as $f) {
                $d = $this->haversineMeters($lat, $lng, (float) $f->latitude, (float) $f->longitude);
                $scored[] = [
                    'id' => (int) $f->id,
                    'name' => (string) $f->name,
                    'latitude' => $f->latitude,
                    'longitude' => $f->longitude,
                    'barangay' => $f->barangay,
                    'municipality' => $f->municipality,
                    'postal_code' => $f->postal_code,
                    'country' => $f->country,
                    'type' => (string) $f->type,
                    'type_key' => $this->responseTypeKey((string) $f->type),
                    'distance_m' => round($d, 2),
                ];
            }

            usort($scored, fn ($a, $b) => ($a['distance_m'] <=> $b['distance_m']));

            return array_slice($scored, 0, $limit);
        }
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;

        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * (sin($dLam / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $R * $c;
    }
}
