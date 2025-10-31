<?php

namespace Database\Seeders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get(database_path('data/barangays.json'));
        $barangays = json_decode($json, true);

        $data = [];

        foreach ($barangays as $b) {
            $data[] = [
                'name'       => strtolower($b['name']),
                'city'       => strtolower($b['city']),
                'province'   => 'pampanga',
                'latitude'   => (float) rtrim($b['latitude'], ','),
                'longitude'  => (float) rtrim($b['longitude'], ','),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('barangays')->upsert(
            $data,
            ['name', 'city'], // unique keys
            ['latitude', 'longitude', 'province', 'updated_at']
        );
    }
}
