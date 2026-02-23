<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flooded_geometries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id');
            $table->json('flooded_geojson')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flooded_geometries');
    }
};
