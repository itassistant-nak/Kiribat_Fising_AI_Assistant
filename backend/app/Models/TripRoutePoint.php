<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TripRoutePoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'latitude',
        'longitude',
        'sequence',
        'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'sequence' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(FishingTrip::class, 'trip_id');
    }
}
