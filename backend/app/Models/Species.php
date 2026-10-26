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
        'ciguatera_risk',
        'notes',
    ];

    public function catches()
    {
        return $this->hasMany(CatchRecord::class, 'species_id');
    }
}
