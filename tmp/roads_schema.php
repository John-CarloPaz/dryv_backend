<?php

/**
 * Dumps the gis_data.public.roads column list to help tune visual offset heuristics.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::connection('gis_data')->select(<<<SQL
select column_name, data_type
from information_schema.columns
where table_schema = 'public'
  and table_name = 'roads'
order by ordinal_position
SQL);

foreach ($rows as $r) {
    echo $r->column_name . "\t" . $r->data_type . "\n";
}
