<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FishIdentificationProvider extends Model
{
    protected $table = 'fish_identification_providers';

    protected $fillable = [
        'catch_id',
        'provider_name',
        'model_identifier',
        'suggested_species',
        'scientific_name',
        'confidence',
        'visible_traits',
        'explanation',
        'alternative_species',
        'raw_response',
        'duration_ms',
        'error_status',
    ];

    protected $casts = [
        'confidence'          => 'float',
        'visible_traits'      => 'array',
        'alternative_species' => 'array',
        'duration_ms'         => 'integer',
    ];

    public function catchRecord(): BelongsTo
    {
        return $this->belongsTo(CatchRecord::class, 'catch_id');
    }
}
