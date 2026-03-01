<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$connection = 'gis_data';

$row = DB::connection($connection)->selectOne(
    "SELECT\n" .
    "  COUNT(*)::bigint AS total,\n" .
    "  SUM(CASE WHEN road_type IS NULL OR road_type = '' THEN 1 ELSE 0 END)::bigint AS missing_type\n" .
    "FROM road_edges_flooded"
);

echo "total_edges=" . ($row->total ?? 'null') . PHP_EOL;
echo "missing_type=" . ($row->missing_type ?? 'null') . PHP_EOL;
