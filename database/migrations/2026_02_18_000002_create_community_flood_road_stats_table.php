<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_flood_road_stats', function (Blueprint $table) {
            $table->id();

            // `roads` lives in the external GIS DB, so we store the road identifier (gid) as an integer.
            $table->unsignedBigInteger('road_gid');
            $table->integer('segment_key');

            // Center of reported snapped points for this segment (for map display).
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();

            $table->double('chi_score')->default(0.0);
            $table->unsignedTinyInteger('risk_level')->default(0);
            $table->unsignedInteger('reports_count')->default(0);

            // Average of estimated_depth values for this segment (within the rolling window).
            $table->double('avg_estimated_depth')->nullable();
            $table->dateTime('last_reported_at')->nullable();

            $table->timestamps();

            $table->unique(['road_gid', 'segment_key']);
            $table->index(['risk_level', 'chi_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_flood_road_stats');
    }
};
