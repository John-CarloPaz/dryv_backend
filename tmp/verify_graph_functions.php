<?php

/**
 * Verifies that the gis_data graph routing SQL helpers are installed
 * and expose the expected return columns.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$connection = 'gis_data';

$rows = DB::connection($connection)->select(
    "SELECT pg_get_function_result('compute_safe_route_geom(text,text,bigint,bigint)'::regprocedure) AS sig\n" .
    "UNION ALL\n" .
    "SELECT pg_get_function_result('compute_safe_route_geom(text,text,bigint,bigint,boolean)'::regprocedure) AS sig"
);

foreach ($rows as $r) {
    echo ($r->sig ?? '') . "\n";
}

// Smoke-test: execute a tiny route between two vertices to ensure the function runs.
try {
    $row = DB::connection($connection)->selectOne(
        "SELECT * FROM compute_safe_route_geom('car','driving', " .
        "(SELECT id FROM roads_noded_vertices_pgr LIMIT 1), " .
        "(SELECT id FROM roads_noded_vertices_pgr OFFSET 1 LIMIT 1))"
    );

    if ($row) {
        echo "OK: compute_safe_route_geom executed. Columns: " . implode(', ', array_keys((array) $row)) . "\n";
    } else {
        echo "WARN: compute_safe_route_geom returned no row (graph may be disconnected at sampled vertices).\n";
    }
} catch (Throwable $e) {
    echo "ERROR: compute_safe_route_geom failed: " . $e->getMessage() . "\n";
}
