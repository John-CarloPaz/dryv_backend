<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$connection = 'gis_data';

$rows = DB::connection($connection)->select(
    "SELECT COALESCE(road_type, '') AS road_type, COUNT(*)::bigint AS c\n" .
    "FROM road_edges_flooded\n" .
    "GROUP BY COALESCE(road_type, '')\n" .
    "ORDER BY c DESC\n" .
    "LIMIT 50"
);

foreach ($rows as $r) {
    echo str_pad((string) $r->road_type, 20) . "\t" . (string) $r->c . PHP_EOL;
}
