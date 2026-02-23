<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::connection('gis_data')->select(
    "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'roads' ORDER BY ordinal_position"
);

echo 'columns=' . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo $r->column_name . "\t" . $r->data_type . PHP_EOL;
}

$sr = DB::connection('gis_data')->selectOne("SELECT ST_SRID(geom) AS srid FROM roads WHERE geom IS NOT NULL LIMIT 1");
$srid = ($sr && isset($sr->srid)) ? $sr->srid : null;
echo 'srid=' . ($srid === null ? 'null' : $srid) . PHP_EOL;

// Sample lookup for a coordinate seen in a step maneuver.
$lng = 120.5921562;
$lat = 15.1528317;

$row = DB::connection('gis_data')->selectOne(
    "SELECT COALESCE(NULLIF(BTRIM(name), ''), NULLIF(BTRIM(ref), '')) AS nm\n" .
    "FROM roads\n" .
    "WHERE geom IS NOT NULL\n" .
    "ORDER BY ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)\n" .
    "LIMIT 1",
    [$lng, $lat]
);

echo 'sample_nearest_name=' . (($row && isset($row->nm)) ? ($row->nm ?? '') : '') . PHP_EOL;

$row2 = DB::connection('gis_data')->selectOne(
    "SELECT COALESCE(NULLIF(BTRIM(name), ''), NULLIF(BTRIM(ref), '')) AS nm\n" .
    "FROM roads\n" .
    "WHERE geom IS NOT NULL\n" .
    "  AND COALESCE(NULLIF(BTRIM(name), ''), NULLIF(BTRIM(ref), '')) IS NOT NULL\n" .
    "  AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 250)\n" .
    "ORDER BY ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)\n" .
    "LIMIT 1",
    [$lng, $lat, $lng, $lat]
);

echo 'sample_nearby_named=' . (($row2 && isset($row2->nm)) ? ($row2->nm ?? '') : '') . PHP_EOL;

// Additional coordinates reported with empty names
$samples = [
    ['lng' => 120.5852609, 'lat' => 15.160231],
    ['lng' => 120.5833949, 'lat' => 15.1665051],
];

foreach ($samples as $s) {
    $lng = $s['lng'];
    $lat = $s['lat'];

    $nearest = DB::connection('gis_data')->selectOne(
        "SELECT name, ref, type,\n" .
        "       COALESCE(NULLIF(BTRIM(name), ''), NULLIF(BTRIM(ref), '')) AS nm\n" .
        "FROM roads\n" .
        "WHERE geom IS NOT NULL\n" .
        "ORDER BY ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)\n" .
        "LIMIT 1",
        [$lng, $lat]
    );

    $named = DB::connection('gis_data')->selectOne(
        "SELECT name, ref, type,\n" .
        "       COALESCE(NULLIF(BTRIM(name), ''), NULLIF(BTRIM(ref), '')) AS nm\n" .
        "FROM roads\n" .
        "WHERE geom IS NOT NULL\n" .
        "  AND COALESCE(NULLIF(BTRIM(name), ''), NULLIF(BTRIM(ref), '')) IS NOT NULL\n" .
        "  AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 250)\n" .
        "ORDER BY ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)\n" .
        "LIMIT 1",
        [$lng, $lat, $lng, $lat]
    );

    echo "sample=" . $lng . "," . $lat . PHP_EOL;
    echo "  nearest_nm=" . (($nearest && isset($nearest->nm)) ? ($nearest->nm ?? '') : '') . PHP_EOL;
    echo "  nearest_name=" . (($nearest && isset($nearest->name)) ? ($nearest->name ?? '') : '') . PHP_EOL;
    echo "  nearest_ref=" . (($nearest && isset($nearest->ref)) ? ($nearest->ref ?? '') : '') . PHP_EOL;
    echo "  nearby_named_nm=" . (($named && isset($named->nm)) ? ($named->nm ?? '') : '') . PHP_EOL;
}
