<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$edgesSql = "SELECT id, source, target, length_m AS cost, length_m AS reverse_cost FROM road_edges_flooded";
$restrSql = "SELECT -1::float8 AS to_cost, 0::bigint AS target_id, ARRAY[]::bigint[] AS via_path WHERE false";

$start = DB::connection('gis_data')->selectOne("SELECT id::bigint AS id FROM roads_noded_vertices_pgr LIMIT 1");
$vid = (int)($start->id ?? 0);

$sql = "SELECT * FROM pgr_turnRestrictedPath($$" . $edgesSql . "$$, $$" . $restrSql . "$$, ?, ?, 2, false, true, true, true) LIMIT 5";

try {
    $rows = DB::connection('gis_data')->select($sql, [$vid, $vid]);
    echo 'rows=' . count($rows) . PHP_EOL;
    if ($rows) {
        echo 'first=' . json_encode($rows[0]) . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
