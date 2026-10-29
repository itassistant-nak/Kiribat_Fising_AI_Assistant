<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatchRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'user_id',
        'client_uuid',
        'species_id',
        'ai_species_suggestion',
        'ai_confidence',
        'ai_metadata',
        'quantity',
        'weight_kg',
        'caught_at',
        'photo_url',
        'latitude',
        'longitude',
        'verification_status',
        'is_catch',
        'verified_by_user_id',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'ai_confidence' => 'float',
        'ai_metadata' => 'array',
        'quantity' => 'integer',
        'weight_kg' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'is_catch' => 'boolean',
        'caught_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Scope to only genuine catches submitted by fishers.
     */
    public function scopeOnlyFisherCatches($query)
    {
        return $query->where('is_catch', true)
            ->where('verification_status', '!=', 'not_a_catch')
            ->whereHas('user', function ($q) {
                $q->where('role', 'fisher');
            });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function trip()
    {
        return $this->belongsTo(FishingTrip::class, 'trip_id');
    }

    public function species()
    {
        return $this->belongsTo(Species::class, 'species_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Scope to format response with GPS protection if user is not authorized.
     */
    public function toArrayProtected(bool $canViewPreciseGps = false): array
    {
        $data = $this->toArray();
        if (!$canViewPreciseGps) {
            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['gps_protected'] = true;
        } else {
            $data['gps_protected'] = false;
        }
        return $data;
    }
}
