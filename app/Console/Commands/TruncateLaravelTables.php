<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TruncateLaravelTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:truncate-laravel-tables {--force : Do not ask for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Truncate tables that were created by this application\'s migrations only.';

    public function handle(): int
    {
        $migrationsPath = database_path('migrations');

        if (! File::isDirectory($migrationsPath)) {
            $this->error('Migrations directory not found: ' . $migrationsPath);
            return 1;
        }

        $files = File::files($migrationsPath);
        $tables = [];

        foreach ($files as $file) {
            $contents = File::get($file->getRealPath());

            // Look for Schema::create('table') or Schema::create("table")
            if (preg_match_all('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches)) {
                foreach ($matches[1] as $t) {
                    $tables[] = $t;
                }
            }

            // Also capture Schema::table('table', ...) when migrations alter
            if (preg_match_all('/Schema::table\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches2)) {
                foreach ($matches2[1] as $t) {
                    $tables[] = $t;
                }
            }
        }

        $tables = array_values(array_unique($tables));

        // Always exclude migrations table even if detected
        $tables = array_filter($tables, function ($t) {
            return $t !== 'migrations';
        });

        if (empty($tables)) {
            $this->info('No tables detected from migration files. Nothing to truncate.');
            return 0;
        }

        $this->info('The following tables were discovered from migration files:');
        foreach ($tables as $t) {
            $this->line(' - ' . $t);
        }

        if (! $this->option('force')) {
            if (! $this->confirm('Do you want to truncate these tables? This will remove ALL data in them.')) {
                $this->info('Aborted.');
                return 0;
            }
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        try {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                foreach ($tables as $table) {
                    DB::table($table)->truncate();
                    Log::info('Truncated table (mysql).', ['table' => $table]);
                }
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'pgsql') {
                foreach ($tables as $table) {
                    // Postgres: use TRUNCATE ... RESTART IDENTITY CASCADE to handle FKs
                    DB::statement(sprintf('TRUNCATE TABLE "%s" RESTART IDENTITY CASCADE', $table));
                    Log::info('Truncated table (pgsql).', ['table' => $table]);
                }
            } elseif ($driver === 'sqlite') {
                foreach ($tables as $table) {
                    DB::statement(sprintf('DELETE FROM "%s"', $table));
                    DB::statement(sprintf("DELETE FROM sqlite_sequence WHERE name='%s'", $table));
                    Log::info('Truncated table (sqlite).', ['table' => $table]);
                }
            } else {
                // Fallback generic truncate
                foreach ($tables as $table) {
                    DB::table($table)->truncate();
                    Log::info('Truncated table (generic).', ['table' => $table]);
                }
            }

            $this->info('Truncation complete.');
            return 0;
        } catch (\Exception $e) {
            Log::error('Error truncating tables.', ['error' => $e->getMessage()]);
            $this->error('Error truncating tables: ' . $e->getMessage());
            return 1;
        }
    }
}
