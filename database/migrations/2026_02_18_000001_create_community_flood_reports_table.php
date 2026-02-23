<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_flood_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // `roads` lives in the external GIS DB, so we store the road identifier (gid) as an integer.
            $table->unsignedBigInteger('road_gid');

            $table->decimal('report_lat', 10, 7);
            $table->decimal('report_lng', 10, 7);

            // Snapped point on the road geometry (closest point) for client display.
            $table->decimal('snapped_lat', 10, 7);
            $table->decimal('snapped_lng', 10, 7);
            $table->double('meters_away')->nullable();

            // Location along the road geometry (0..1) and a discrete segment bucket key.
            // segment_key = floor((fraction * road_length_m) / bin_size_m)
            $table->double('road_line_fraction')->nullable();
            $table->integer('segment_key')->nullable();

            $table->foreignId('barangay_id')->nullable()->constrained('barangays');
            $table->foreignId('weather_id')->nullable()->constrained('weathers');

            $table->double('hazard_weight')->default(0.0);
            // Rainfall input used for CHI (currently based on `Weather.runoff`).
            $table->double('rainfall')->default(0.0);

            // Estimated flood depth reported by the user:
            // 1 Ankle-Deep, 2 Knee-Deep, 3 Waist Deep, 4 Chest Deep
            $table->unsignedTinyInteger('estimated_depth')->nullable();

            // Snapshot of the aggregate at submission time.
            $table->double('chi_score')->default(0.0);
            $table->unsignedTinyInteger('risk_level')->default(0);

            $table->timestamps();

            $table->index(['road_gid', 'created_at']);
            $table->index(['road_gid', 'segment_key', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_flood_reports');
    }
};
