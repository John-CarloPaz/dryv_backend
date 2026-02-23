<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$res = DB::connection('gis_data')->selectOne("SELECT pgr_createTopology('roads_noded', 0.00001, 'geom', 'gid', 'source', 'target') AS r");
var_dump($res);
