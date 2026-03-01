<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$edge = DB::connection('gis_data')->selectOne('SELECT id, source, target FROM road_edges_flooded ORDER BY id LIMIT 1');
if (!$edge) {
    echo "No edges found\n";
    exit(1);
}

$start = (int) $edge->source;
$end = (int) $edge->target;

$edgesSql = "SELECT id, source, target, length_m AS cost, length_m AS reverse_cost FROM road_edges_flooded";

$variants = [
    'to_cost/target_id/via_path' => "SELECT -1::float8 AS to_cost, 0::bigint AS target_id, ARRAY[]::bigint[] AS via_path WHERE false",
    'cost/target_id/via_path' => "SELECT -1::float8 AS cost, 0::bigint AS target_id, ARRAY[]::bigint[] AS via_path WHERE false",
    'id/cost/path' => "SELECT 1::bigint AS id, -1::float8 AS cost, ARRAY[]::bigint[] AS path WHERE false",
    'id/cost/via_path' => "SELECT 1::bigint AS id, -1::float8 AS cost, ARRAY[]::bigint[] AS via_path WHERE false",
];

foreach ($variants as $label => $restrSql) {
    echo "== $label ==\n";
    $sql = "SELECT * FROM pgr_turnRestrictedPath($$" . $edgesSql . "$$, $$" . $restrSql . "$$, ?, ?, 2, true, true, true, true) LIMIT 5";
    try {
        $rows = DB::connection('gis_data')->select($sql, [$start, $end]);
        echo 'ok rows=' . count($rows) . PHP_EOL;
        if ($rows) {
            echo 'first=' . json_encode($rows[0]) . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    }
}
