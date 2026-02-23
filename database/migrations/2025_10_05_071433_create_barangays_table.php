<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string("city");
            $table->string("name");
            $table->decimal("latitude", 10, 7);
            $table->decimal("longitude", 10, 7);
            $table->string("province");
            $table->string("country")->default("Philippines");
            $table->string("country_code")->default("PH");
            $table->unique(['city', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
