<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var \App\Services\SafeRoutingService $svc */
$svc = $app->make(\App\Services\SafeRoutingService::class);

$origin = ['lat' => 15.0616066, 'lng' => 120.7320498];
$destination = ['lat' => 15.16991694, 'lng' => 120.57924746];

$r = $svc->findSafeRoute($origin, $destination, 'driving', 'car', [], 5, false);

echo json_encode($r['_meta']['timings_ms'] ?? null, JSON_PRETTY_PRINT) . PHP_EOL;
