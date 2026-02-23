<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var App\Services\SafeRoutingService $svc */
$svc = $app->make(App\Services\SafeRoutingService::class);

$origin = ['lat' => 15.144867, 'lng' => 120.595551];
$destination = ['lat' => 15.166677, 'lng' => 120.580283];

$avoidMotorway = true;
$exclude = $avoidMotorway ? ['motorway'] : [];
$route = $svc->findSafeRoute($origin, $destination, 'driving', 'car', $exclude, 20, $avoidMotorway);

$meta = $route['_meta']['road_name_enrichment'] ?? null;
echo 'engine=' . ($route['_meta']['engine'] ?? 'unknown') . PHP_EOL;
echo 'road_name_enrichment=' . json_encode($meta) . PHP_EOL;
echo 'risk_level=' . ($route['_meta']['risk_level'] ?? '') . PHP_EOL;
echo 'confidence=' . ($route['_meta']['confidence'] ?? '') . PHP_EOL;
echo 'eta_at=' . ($route['_meta']['eta_at'] ?? '') . PHP_EOL;
echo 'cache=' . json_encode($route['_meta']['cache'] ?? null) . PHP_EOL;
echo 'timings_ms=' . json_encode($route['_meta']['timings_ms'] ?? null) . PHP_EOL;
echo 'visual_offset=' . json_encode($route['_meta']['visual_offset'] ?? null) . PHP_EOL;

$steps = $route['routes'][0]['legs'][0]['steps'] ?? [];

$routeGeom = $route['routes'][0]['geometry'] ?? null;
if (is_array($routeGeom) && ($routeGeom['type'] ?? null) === 'LineString') {
    $cnt = is_array($routeGeom['coordinates'] ?? null) ? count($routeGeom['coordinates']) : 0;
    echo 'route_geometry_points=' . $cnt . PHP_EOL;
}

for ($i = 0; $i < min(12, count($steps)); $i++) {
    $s = $steps[$i];
    $g = $s['geometry']['coordinates'] ?? [];
    $gc = is_array($g) ? count($g) : 0;
    echo str_pad((string)$i, 2, ' ', STR_PAD_LEFT) . ' pts=' . $gc . ' name=' . ($s['name'] ?? '') . ' | ' . (($s['maneuver']['instruction'] ?? '') ?: '') . PHP_EOL;
}

$last = $steps[count($steps) - 1] ?? null;
if (is_array($last)) {
    echo 'last_maneuver=' . json_encode($last['maneuver'] ?? null) . PHP_EOL;
}

$sample = $steps[1] ?? null;
if (is_array($sample)) {
    echo 'sample_intersections=' . json_encode($sample['intersections'] ?? null) . PHP_EOL;
}
