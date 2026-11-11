<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['observed_at' => 'datetime', 'wildfire_smoke' => 'boolean'];
}
