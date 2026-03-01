<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::connection('gis_data')->select(
    "SELECT proname, pg_get_function_identity_arguments(p.oid) AS args\n" .
    "FROM pg_proc p\n" .
    "JOIN pg_namespace n ON n.oid=p.pronamespace\n" .
    "WHERE n.nspname IN ('public','pgrouting')\n" .
    "  AND proname ILIKE 'pgr_%turn%'\n" .
    "ORDER BY proname, args"
);

foreach ($rows as $r) {
    echo $r->proname . '(' . $r->args . ')' . PHP_EOL;
}

echo "---core---\n";

$rows2 = DB::connection('gis_data')->select(
    "SELECT proname, pg_get_function_identity_arguments(p.oid) AS args\n" .
    "FROM pg_proc p\n" .
    "JOIN pg_namespace n ON n.oid=p.pronamespace\n" .
    "WHERE n.nspname IN ('public','pgrouting')\n" .
    "  AND proname IN ('pgr_trsp','pgr_turnRestrictedPath','pgr_dijkstra')\n" .
    "ORDER BY proname, args"
);

foreach ($rows2 as $r) {
    echo $r->proname . '(' . $r->args . ')' . PHP_EOL;
}
