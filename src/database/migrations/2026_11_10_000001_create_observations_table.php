<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $t) {
            $t->id();
            $t->string('record_id', 32)->unique();
            $t->dateTime('observed_at')->index();
            $t->string('province', 64)->index();
            $t->string('region_type', 96)->index();
            $t->string('station', 128)->index();
            $t->float('pm25');
            $t->float('pm10');
            $t->float('so2');
            $t->float('no2');
            $t->float('co');
            $t->float('o3');
            $t->float('temperature');
            $t->float('humidity');
            $t->float('wind_speed');
            $t->float('rainfall');
            $t->unsignedSmallInteger('ispu')->index();
            $t->string('ispu_category', 32)->index();
            $t->float('who_exceedance');
            $t->float('pm_ratio');
            $t->float('combustion_index');
            $t->float('stagnation_index');
            $t->boolean('wildfire_smoke')->index();
            $t->string('risk_tier', 64)->index();
            $t->float('ispa_burden');
            $t->text('rationale');
            $t->float('confidence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
