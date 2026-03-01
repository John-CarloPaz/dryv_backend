<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CrucialFacilitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get(database_path('data/crucial_establishement.json'));
        $facilities = json_decode($json, true);

        if (!is_array($facilities)) {
            return;
        }

        $rowsByKey = [];

        foreach ($facilities as $row) {
            $name = isset($row['Name']) ? trim((string) $row['Name']) : '';
            $barangay = isset($row['Barangay']) ? trim((string) $row['Barangay']) : '';
            $municipality = isset($row['Municipality']) ? trim((string) $row['Municipality']) : '';
            $type = isset($row['Type']) ? trim((string) $row['Type']) : '';

            if ($name === '' || $municipality === '' || $type === '') {
                continue;
            }

            $normalized = [
                'name' => strtolower($name),
                'latitude' => isset($row['Latitude']) ? (float) $row['Latitude'] : null,
                'longitude' => isset($row['Longitude']) ? (float) $row['Longitude'] : null,
                'barangay' => $barangay === '' ? null : strtolower($barangay),
                'municipality' => strtolower($municipality),
                'postal_code' => array_key_exists('Postal Code', $row) && $row['Postal Code'] !== null
                    ? trim((string) $row['Postal Code'])
                    : null,
                'country' => isset($row['Country']) ? strtoupper(trim((string) $row['Country'])) : null,
                'type' => strtolower($type),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $key = implode('|', [
                $normalized['name'],
                $normalized['barangay'] ?? '',
                $normalized['municipality'],
                $normalized['type'],
            ]);

            // If duplicates exist in the input file, last row wins.
            $rowsByKey[$key] = $normalized;
        }

        $data = array_values($rowsByKey);

        if ($data === []) {
            return;
        }

        DB::table('crucial_facilities')->upsert(
            $data,
            ['name', 'barangay', 'municipality', 'type'],
            ['latitude', 'longitude', 'postal_code', 'country', 'updated_at']
        );
    }
}
