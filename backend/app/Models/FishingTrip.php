<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FishingTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vessel_name',
        'started_at',
        'ended_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function routePoints()
    {
        return $this->hasMany(TripRoutePoint::class, 'trip_id')->orderBy('sequence', 'asc');
    }

    public function catches()
    {
        return $this->hasMany(CatchRecord::class, 'trip_id');
    }
}
