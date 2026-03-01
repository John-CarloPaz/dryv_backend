<?php

namespace App\Http\Controllers;

use App\Http\Requests\SafeRouteRequest;
use App\Services\SafeRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SafeRouteController extends Controller
{
    private SafeRoutingService $routingService;

    public function __construct(SafeRoutingService $routingService)
    {
        $this->routingService = $routingService;
    }

    public function route(SafeRouteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $origin = [
            'lat' => $data['origin']['lat'],
            'lng' => $data['origin']['lng'],
        ];

        $destination = [
            'lat' => $data['destination']['lat'],
            'lng' => $data['destination']['lng'],
        ];

        $profile = $data['routing_profile'] ?? 'driving';
        $vehicleType = $data['vehicle_type'] ?? 'car';
        $exclude = $data['exclude'] ?? [];
        $avoidMotorway = $data['avoid_motorway'] ?? null;
        $avoidTolls = $data['avoid_tolls'] ?? null;
        $maxAttempts = $data['max_attempts'] ?? null;
        $toggleCommunityReport = $data['toggle_community_report'] ?? false;

        // Normalize per product spec.
        $vehicleTypeNorm = is_string($vehicleType) ? strtolower(trim($vehicleType)) : 'car';
        $profileNorm = is_string($profile) ? strtolower(trim($profile)) : 'driving';

        if ($vehicleTypeNorm === 'walking') {
            // Walking is a first-class "vehicle_type" for clients.
            $profileNorm = 'walking';
            // Walking should never try to use motorways; let the engine decide, but avoid_motorway
            // is a reasonable default.
            if ($avoidMotorway === null) {
                $avoidMotorway = true;
            }
        }

        // Motor: when avoid_tolls is toggled, avoid motorways (per spec).
        if ($vehicleTypeNorm === 'motor' && $avoidTolls === true && $avoidMotorway === null) {
            $avoidMotorway = true;
        }

        // Car: avoid_tolls is not supported by the product spec; force it off.
        if ($vehicleTypeNorm === 'car' && $avoidTolls === true) {
            $avoidTolls = false;
        }

        Log::info('SafeRouteController: received safe route request', [
            'origin' => $origin,
            'destination' => $destination,
            'routing_profile' => $profileNorm,
            'vehicle_type' => $vehicleTypeNorm,
            'exclude' => $exclude,
            'avoid_motorway' => $avoidMotorway,
            'avoid_tolls' => $avoidTolls,
            'toggle_community_report' => $toggleCommunityReport,
            'max_attempts' => $maxAttempts,
        ]);

        try {
            $route = $this->routingService->findSafeRoute($origin, $destination, $profileNorm, $vehicleTypeNorm, $exclude, $maxAttempts, $avoidMotorway, (bool) $toggleCommunityReport);

            Log::info('SafeRouteController: safe route computed successfully', [
                'engine' => $route['_meta']['engine'] ?? 'unknown',
                'distance' => Arr::get($route, 'routes.0.distance'),
                'duration' => Arr::get($route, 'routes.0.duration'),
                'max_risk_level' => Arr::get($route, '_meta.max_risk_level'),
                'attempts' => $route['_meta']['attempts'] ?? null,
                'waypoints' => $route['waypoints'] ?? null,
            ]);

            // Return a Mapbox-like payload (routes/waypoints/code/uuid) so clients can
            // directly consume turn-by-turn steps.
            return response()->json($route);
        } catch (RuntimeException $e) {
            Log::warning('SafeRouteController: routing failed with known error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('SafeRouteController: unexpected routing failure', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unexpected error while computing route.',
            ], 500);
        }
    }
}
