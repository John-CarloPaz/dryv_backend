<?php

namespace App\Jobs;

use App\Models\FloodedGeometry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UploadGeoJsonToMapbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        try {
            // FeatureCollection build removed: we stream features to a
            // line-delimited GeoJSON file below to avoid high memory usage.

            // ------------------------------------------
            // 2. Save to a line-delimited GeoJSON (GeoJSONL) temp file
            // ------------------------------------------
            $file = storage_path('app/flooded_dataset.geojson.ld');

            // Mapbox config needed by uploader
            $username  = config('services.mapbox.username');
            $token     = config('services.mapbox.token');

            if (empty($token) || empty($username)) {
                Log::error('Missing Mapbox configuration (MAPBOX_ACCESS_TOKEN or MAPBOX_USERNAME).');
                return;
            }

            if (strpos($token, 'pk.') === 0) {
                Log::warning('Mapbox token looks like a public token (starts with pk.). Use a secret token with tilesets:write/uploads:write scopes.');
                // continue, but likely to fail with 401
            }

            // Use a single, fixed tileset (dryv_b) and always replace
            // its source on each upload.
            $tilesetIdFull = config('services.mapbox.tileset_id')
                ?: (config('services.mapbox.tileset_b') ?: ($username . '.dryv_tileset_1'));

            // Mapbox tileset ids must be in the form "{username}.{tileset_id}".
            // If MAPBOX_TILESET_ID is set to only the tileset name, prefix it.
            if (!str_contains($tilesetIdFull, '.') && !empty($username)) {
                $tilesetIdFull = $username . '.' . ltrim($tilesetIdFull, '.');
            }

            // Prefer the username embedded in the tileset id (if provided) to
            // avoid mismatches where MAPBOX_USERNAME doesn't match the token account.
            $tilesetIdParts = explode('.', $tilesetIdFull, 2);
            $effectiveUsername = count($tilesetIdParts) === 2 ? $tilesetIdParts[0] : $username;

            if (!empty($effectiveUsername) && $effectiveUsername !== $username) {
                Log::warning('MAPBOX_USERNAME does not match tileset id owner; using tileset owner for API calls.', [
                    'mapbox_username' => $username,
                    'tileset_owner' => $effectiveUsername,
                    'tileset_id' => $tilesetIdFull,
                ]);
            }

            // Derive source id from tileset id (part after dot) or fallback
            if (count($tilesetIdParts) === 2) {
                $sourceId = $tilesetIdParts[1];
            } else {
                $sourceId = $tilesetIdFull;
            }

            // Build a minimal recipe referencing the uploaded source
            $recipe = [
                'version' => 1,
                'layers' => [
                    'flooded' => [
                        'source' => "mapbox://tileset-source/{$effectiveUsername}/{$sourceId}",
                        'minzoom' => 10,
                        'maxzoom' => 14,
                        'tiles' => [
                            'union' => [
                                [
                                    'group_by' => ['gid'],
                                    'aggregate' => [
                                        'risk_level' => 'arbitrary',
                                        'rwr'        => 'arbitrary',
                                    ],
                                    'simplification' => 0,
                                ],
                            ],
                        ],
                        'features' => [
                            'filter' => [
                                'step',
                                ['zoom'],
                                false, 12, true, 15, false,
                            ],
                            'attributes' => [
                                'allowed_output' => ['gid', 'risk_level'],
                            ],
                        ],
                    ],
                ],
            ];

            $tilesetPayload = [
                'recipe' => $recipe,
                'name' => config('services.mapbox.tileset_name', 'Flooded Tileset'),
                'description' => config('services.mapbox.tileset_description', 'Flooded polygons tileset'),
                'attribution' => [],
            ];

            // Stream all features to one line-delimited GeoJSONL file to avoid
            // multiple source files (Mapbox limits stored files per source).
            // This writes directly from the DB cursors to disk to avoid memory spikes.
            $written = 0;
            $handle = fopen($file, 'w');
            if ($handle === false) {
                Log::error('Unable to open temp file for writing.', ['file' => $file]);
                return;
            }

            foreach (FloodedGeometry::cursor() as $fg) {
                $geojson = json_decode($fg->flooded_geojson, true);
                if (!empty($geojson['features'])) {
                    foreach ($geojson['features'] as $feature) {
                        fwrite($handle, json_encode($feature) . "\n");
                        $written++;
                    }
                }
            }

            fclose($handle);

            if ($written === 0) {
                Log::info('No features found; skipping upload.');
                @unlink($file);
                return;
            }

            // Build source upload URL (include access_token as Mapbox sometimes
            // requires it on this endpoint to avoid "Direct access not allowed").
            $sourceUploadUrl = "https://api.mapbox.com/tilesets/v1/sources/{$effectiveUsername}/{$sourceId}?access_token=" . urlencode($token);
            Log::info('Replacing tileset source.', [
                'file' => $file,
                'bytes' => filesize($file),
                'username' => $effectiveUsername,
                'tileset_id' => $tilesetIdFull,
                'source' => $sourceId,
                'url' => $sourceUploadUrl,
            ]);

            $readHandle = fopen($file, 'r');
            if ($readHandle === false) {
                Log::error('Failed to open file for reading prior to upload.', ['file' => $file]);
                @unlink($file);
                return;
            }

            try {
                // Use PUT to replace the existing tileset source file
                $uploadResp = Http::withToken($token)
                    ->timeout(600)
                    ->attach('file', $readHandle, basename($file))
                    ->put($sourceUploadUrl);

                if (! $uploadResp->successful()) {
                    $status = $uploadResp->status();
                    $body = $uploadResp->body();
                    Log::error('Failed to upload (replace) tileset source.', ['status' => $status, 'response' => $body]);

                    // If source upload returns 404, attempt to (re)create the tileset
                    // and retry the source upload once. Note: the source endpoint is independent
                    // of tilesets; a 404 here usually indicates account/username mismatch.
                    if ($status === 404) {
                        Log::warning('Source upload returned 404; retrying once (usually account/username mismatch).', [
                            'tileset_id' => $tilesetIdFull,
                            'source' => $sourceId,
                        ]);

                        if (is_resource($readHandle)) {
                            fclose($readHandle);
                        }

                        $retryHandle = fopen($file, 'r');
                        if ($retryHandle !== false) {
                            $retryResp = Http::withToken($token)
                                ->timeout(600)
                                ->attach('file', $retryHandle, basename($file))
                                ->put($sourceUploadUrl);

                            fclose($retryHandle);

                            if (! $retryResp->successful()) {
                                Log::error('Retry failed to upload (replace) tileset source.', [
                                    'status' => $retryResp->status(),
                                    'response' => $retryResp->body(),
                                ]);
                                @unlink($file);
                                return;
                            }

                            Log::info('Retry: tileset source uploaded successfully.', ['response' => $retryResp->json()]);
                        } else {
                            Log::error('Retry: unable to open temp file for reading.', ['file' => $file]);
                            @unlink($file);
                            return;
                        }
                    } else {
                        if (is_resource($readHandle)) {
                            fclose($readHandle);
                        }
                        @unlink($file);
                        return;
                    }
                } else {
                    Log::info('Tileset source uploaded successfully.', ['response' => $uploadResp->json()]);
                    // Persist chosen ids so future runs reuse them
                    Cache::forever('mapbox_tileset_id', $tilesetIdFull);
                    Cache::forever('mapbox_source_id', $sourceId);
                }
            } catch (\Exception $e) {
                Log::error('Error uploading tileset source.', ['error' => $e->getMessage()]);
                if (is_resource($readHandle)) {
                    fclose($readHandle);
                }
                @unlink($file);
                return;
            } finally {
                if (is_resource($readHandle)) {
                    fclose($readHandle);
                }
            }

            // Now that the source exists, ensure the tileset exists.
            $tilesetCheckUrl = "https://api.mapbox.com/tilesets/v1/{$tilesetIdFull}?access_token=" . urlencode($token);
            try {
                $checkResp = Http::timeout(30)->get($tilesetCheckUrl);
            } catch (\Throwable $e) {
                Log::error('Tileset check request threw an exception.', [
                    'tileset_id' => $tilesetIdFull,
                    'url' => $tilesetCheckUrl,
                    'error' => $e->getMessage(),
                ]);
                @unlink($file);
                return;
            }

            if ($checkResp->successful()) {
                Log::info('Tileset exists; will reuse it.', ['tileset' => $tilesetIdFull]);
            } else {
                Log::warning('Tileset missing; creating it now.', [
                    'tileset_id' => $tilesetIdFull,
                    'status' => $checkResp->status(),
                    'response' => $checkResp->body(),
                ]);

                $createUrl = "https://api.mapbox.com/tilesets/v1/{$tilesetIdFull}?access_token=" . urlencode($token);
                try {
                    $createResp = Http::timeout(60)
                        ->retry(2, 1000)
                        ->post($createUrl, $tilesetPayload);
                } catch (\Throwable $e) {
                    Log::error('Tileset create request threw an exception.', [
                        'tileset_id' => $tilesetIdFull,
                        'url' => $createUrl,
                        'error' => $e->getMessage(),
                    ]);
                    @unlink($file);
                    return;
                }

                if ($createResp->successful()) {
                    Log::info('Tileset created successfully.', ['tileset_id' => $tilesetIdFull, 'response' => $createResp->json()]);
                } else {
                    Log::error('Failed to create tileset.', [
                        'tileset_id' => $tilesetIdFull,
                        'status' => $createResp->status(),
                        'response' => $createResp->body(),
                    ]);
                    @unlink($file);
                    return;
                }
            }

            // Publish the tileset (rebuild tiles) after source replacement.
            $publishUrl = "https://api.mapbox.com/tilesets/v1/{$tilesetIdFull}/publish?access_token=" . urlencode($token);
            $publishResp = Http::timeout(60)
                ->retry(2, 1000)
                ->post($publishUrl);

            if ($publishResp->successful()) {
                Log::info('Tileset publish initiated.', ['tileset_id' => $tilesetIdFull, 'response' => $publishResp->json()]);
            } else {
                Log::error('Failed to publish tileset.', ['status' => $publishResp->status(), 'response' => $publishResp->body()]);
            }

            // cleanup temporary file
            @unlink($file);

        } catch (\Exception $e) {
            Log::error("Error updating Mapbox dataset.", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    
}
