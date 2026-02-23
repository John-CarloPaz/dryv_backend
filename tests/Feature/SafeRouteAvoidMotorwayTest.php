<?php

namespace Tests\Feature;

use App\Services\SafeRoutingService;
use Mockery;
use Tests\TestCase;

class SafeRouteAvoidMotorwayTest extends TestCase
{
    public function test_safe_route_accepts_avoid_motorway_and_forwards_to_service(): void
    {
        $mock = Mockery::mock(SafeRoutingService::class);
        $mock->shouldReceive('findSafeRoute')
            ->once()
            ->withArgs(function ($origin, $destination, $profile, $vehicleType, $exclude, $maxAttempts, $avoidMotorway) {
                return $avoidMotorway === true
                    && ($profile === 'driving')
                    && ($vehicleType === 'car')
                    && is_array($exclude)
                    && !in_array('motorway', $exclude, true);
            })
            ->andReturn([
                'code' => 'Ok',
                'routes' => [],
                'waypoints' => [],
                'uuid' => '00000000-0000-0000-0000-000000000000',
                '_meta' => [
                    'engine' => 'mapbox',
                ],
            ]);

        $this->app->instance(SafeRoutingService::class, $mock);

        $response = $this->postJson('/api/route/safe', [
            'origin' => ['lat' => 15.166754, 'lng' => 120.580551],
            'destination' => ['lat' => 15.154253, 'lng' => 120.592161],
            'routing_profile' => 'driving',
            'vehicle_type' => 'car',
            'avoid_motorway' => true,
        ]);

        $response->assertOk();
    }
}
