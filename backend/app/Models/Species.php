<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Species extends Model
{
    use HasFactory;

    protected $table = 'species';

    protected $fillable = [
        'kiribati_name',
        'english_name',
        'scientific_name',
        'scientific_name_as_provided',
        'ciguatera_risk',
        'verification_status',
        'record_type',
        'notes',
        'photo_url',
        'visual_features',
    ];

    protected $casts = [
        'visual_features' => 'array',
    ];

    public function catches()
    {
        return $this->hasMany(CatchRecord::class, 'species_id');
    }

    public function advisoryZones()
    {
        return $this->belongsToMany(AdvisoryZone::class, 'advisory_zone_species')
            ->withPivot('risk_level', 'notes')
            ->withTimestamps();
    }
}
