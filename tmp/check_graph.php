<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'roads_noded',
    'roads_noded_vertices_pgr',
];

foreach ($tables as $t) {
    $reg = DB::connection('gis_data')->selectOne("SELECT to_regclass('public.$t') AS t");
    echo $t . " exists=" . (($reg && $reg->t) ? 'yes' : 'no') . "\n";
}

$schemas = DB::connection('gis_data')->select("SELECT table_schema FROM information_schema.tables WHERE table_name = 'roads_noded_vertices_pgr' ORDER BY table_schema");
echo "roads_noded_vertices_pgr schemas=" . json_encode(array_map(fn($r) => $r->table_schema, $schemas)) . "\n";

$rel = DB::connection('gis_data')->selectOne("SELECT c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname='roads_noded_vertices_pgr'");
echo "roads_noded_vertices_pgr relkind=" . (($rel && isset($rel->relkind)) ? $rel->relkind : 'null') . "\n";

$rls = DB::connection('gis_data')->selectOne("SELECT c.relrowsecurity, c.relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname='roads_noded_vertices_pgr'");
echo "roads_noded_vertices_pgr rls=" . json_encode($rls) . "\n";

$cols = DB::connection('gis_data')->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema='public' AND table_name='roads_noded_vertices_pgr' ORDER BY ordinal_position");
echo "roads_noded_vertices_pgr cols=" . json_encode($cols) . "\n";

$trigs = DB::connection('gis_data')->selectOne("SELECT COUNT(*) AS c FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname='roads_noded_vertices_pgr' AND NOT t.tgisinternal");
echo "roads_noded_vertices_pgr triggers=" . ($trigs->c ?? 'null') . "\n";

$cntEdges = DB::connection('gis_data')->selectOne('SELECT COUNT(*) AS c FROM roads_noded');
$cntVerts = DB::connection('gis_data')->selectOne('SELECT COUNT(*) AS c FROM roads_noded_vertices_pgr');

$nulls = DB::connection('gis_data')->selectOne('SELECT SUM(CASE WHEN source IS NULL OR target IS NULL THEN 1 ELSE 0 END) AS nulls FROM roads_noded');
$nullGeom = DB::connection('gis_data')->selectOne('SELECT SUM(CASE WHEN geom IS NULL THEN 1 ELSE 0 END) AS nulls FROM roads_noded');
$ranges = DB::connection('gis_data')->selectOne('SELECT MIN(source) AS min_s, MAX(source) AS max_s, MIN(target) AS min_t, MAX(target) AS max_t FROM roads_noded');

echo "roads_noded count=" . ($cntEdges->c ?? 'null') . "\n";
echo "roads_noded_vertices_pgr count=" . ($cntVerts->c ?? 'null') . "\n";
echo "roads_noded null source/target=" . ($nulls->nulls ?? 'null') . "\n";
echo "roads_noded null geom=" . ($nullGeom->nulls ?? 'null') . "\n";
echo "roads_noded source range=" . json_encode($ranges) . "\n";

$sample = DB::connection('gis_data')->selectOne('SELECT id, ST_AsText(the_geom) AS wkt FROM roads_noded_vertices_pgr ORDER BY id LIMIT 1');
echo "sample_vertex=" . json_encode($sample) . "\n";
